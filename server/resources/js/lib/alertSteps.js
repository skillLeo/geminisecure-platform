/**
 * WHICH BUTTON ON A PANIC ALERT IS SUPPOSED TO BE CLICKED FIRST (Hassam, Batch 1, 12 Oct 2026: "make them better
 * sorting, then which one is supposed to be clicked first so it is easy for the user to understand. Don't make any
 * restriction: if the user wants to click any other button then allow him.").
 *
 * The workflow's own order — acknowledge, dispatch, en route, on scene, resolve — read from the steps already
 * recorded, never from a `can_*` flag: Resolve stays pressable the moment an alert is raised (a false alarm needs no
 * dispatch at all), and this only says which already-pressable step is next. It never disables or hides another.
 *
 * `Gemini/Dispatch/Alert.vue` draws the step this names as its one solid button, with a small "Next step" cue; every
 * other live step keeps the outline it drew before this existed.
 */

/**
 * @param {{ acknowledged?: boolean, dispatched?: boolean, responding?: boolean, en_route?: boolean, on_scene?: boolean }} actions
 * @returns {'acknowledge'|'dispatch'|'en_route'|'on_scene'|'resolve'}
 */
export function nextStep(actions) {
    if (!actions?.acknowledged) return 'acknowledge'
    if (!actions.dispatched && !actions.responding) return 'dispatch'
    if (!actions.en_route && !actions.on_scene) return 'en_route'
    if (!actions.on_scene) return 'on_scene'

    return 'resolve'
}

/** The one solid button for the step that is next; every other live step stays the outline it drew before. */
export const stepClass = (actions, key) => (nextStep(actions) === key ? 'primary' : 'outline')
