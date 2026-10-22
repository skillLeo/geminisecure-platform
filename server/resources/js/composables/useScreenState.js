import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The six states a screen has to be able to show.
 *
 * A screen is not finished when its happy path renders. It is finished when
 * all six do — and the other five are the ones that go unreviewed, because
 * reaching them means contriving the data. An empty filtered list needs a
 * search matching nothing, a denied panel needs a second account, and a
 * loading skeleton lasts 80ms on a local machine and cannot be looked at at
 * all.
 *
 * In local development `?_state=` forces one, so all six can be reviewed on
 * demand:
 *
 *     /clients?_state=loading
 *     /clients?_state=empty-filtered
 *     /guards?_state=denied
 *
 * Usage in a page:
 *
 *     const state = useScreenState({ rows: () => props.clients.length, filtered: () => !!props.filters.q })
 *
 *     <SkeletonRows v-if="state.isLoading" />
 *     <EmptyState v-else-if="state.isEmptyFiltered" variant="filtered" ... />
 *
 * The forcing only ever ARRIVES from the server, which refuses it outside
 * local. Nothing here can turn it on by itself.
 *
 * @param {object} [sources]
 * @param {() => number} [sources.rows]      how many records the screen has
 * @param {() => boolean} [sources.filtered] whether a filter or search is applied
 * @param {() => boolean} [sources.denied]   whether the viewer's role is refused
 * @param {() => boolean} [sources.failed]   whether loading the data failed
 */
export function useScreenState(sources = {}) {
    const page = usePage()

    const forced = computed(() => page.props.screenState ?? null)

    /** What the screen would show if nothing were being forced. */
    const actual = computed(() => {
        if (sources.denied?.()) {
            return 'denied'
        }

        if (sources.failed?.()) {
            return 'error'
        }

        const rows = sources.rows?.() ?? 1

        if (rows > 0) {
            return 'populated'
        }

        // Empty because a filter excluded everything is a different screen
        // from empty because nothing exists yet: one offers to clear the
        // filter, the other offers to create the first record. Showing
        // "nothing here yet" to someone who has typed a search is telling
        // them their data is gone.
        return sources.filtered?.() ? 'empty-filtered' : 'empty'
    })

    const current = computed(() => forced.value ?? actual.value)

    return {
        current,
        forced,
        isForced: computed(() => forced.value !== null),
        isLoading: computed(() => current.value === 'loading'),
        isEmpty: computed(() => current.value === 'empty'),
        isEmptyFiltered: computed(() => current.value === 'empty-filtered'),
        isPopulated: computed(() => current.value === 'populated'),
        isError: computed(() => current.value === 'error'),
        isDenied: computed(() => current.value === 'denied'),
    }
}
