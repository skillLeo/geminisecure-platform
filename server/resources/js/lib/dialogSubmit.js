/**
 * A SAVE FROM THE CONSOLE'S DIALOG THAT CANNOT LOSE WHAT WAS TYPED (Hassam, 11 October 2026).
 *
 * A refused save keeps the dialog open, with everything typed and the server's words under the fields; only a save
 * that went through runs `close`. Every save from `ConsoleDialog` goes through this, so nobody has to remember the rule
 * at a call site.
 *
 * HOW: the visit always keeps the page's state (`preserveState: true`), whatever options are passed. Inertia 3.7's
 * `router.post` / `put` / `patch` / `delete` (which `useForm` uses) already default to it; what loses a form is a call
 * that passes `preserveState: false`, a `router.visit` with a method (its default is false), or `preserveState:
 * 'errors'` meeting a refusal the server sends as a flash rather than as a field error. None of those can reach a save
 * made here. A save that went through closes the dialog through `close`, and the page's props arrive as they always do.
 *
 *   import { submitDialog } from '../../../lib/dialogSubmit.js'
 *   submitDialog(form, 'post', url, { close: () => (open.value = false), onSuccess: () => form.reset() })
 *
 * @param {object} form       an Inertia `useForm` form
 * @param {'post'|'put'|'patch'|'delete'} method
 * @param {string} url
 * @param {object} [options]  any visit options; `close` runs after a save that went through, before `onSuccess`
 */
export function submitDialog(form, method, url, { close, ...options } = {}) {
    return form[method](url, {
        preserveScroll: true,
        ...options,
        preserveState: true,
        onSuccess: (page) => {
            close?.()

            return options.onSuccess?.(page)
        },
    })
}
