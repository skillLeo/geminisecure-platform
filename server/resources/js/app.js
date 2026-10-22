import { createApp, h } from 'vue'
import { createInertiaApp, usePage } from '@inertiajs/vue3'
import { loadPage, watchLateChunks } from './chunkRecovery'
import { tabTitle } from './tabTitle'

const appName = import.meta.env.VITE_APP_NAME || 'GeminiSecure'

/*
 * EVERY PAGE IS ITS OWN CHUNK (M11 A3), fetched when the server first names it.
 *
 * This glob used to be `{ eager: true }`, which put all 116 components under Pages/,
 * both consoles and the socket client into ONE 1.13 MB script (1,131,709 bytes
 * built from a342c56; 1,115,170 on production on 25 September) that every sign-in,
 * dashboard and error page downloaded and compiled before drawing anything: opening
 * the estate dashboard shipped the accounting module, the Gemini payroll screens and
 * the dispatch map with it. Lazy, the entry script is Vue, Inertia and this file; a
 * page's own code and its styles arrive with that page and stay for the visit.
 *
 * A NAVIGATION CANNOT ASK FOR A CHUNK A DEPLOY HAS REMOVED. The component name
 * comes from the server's answer, and an answer from a newer release is a 409
 * (Inertia's asset version is the manifest's hash) that reloads the whole page on
 * the new build. So a tab left open across a deploy reloads once, exactly as it
 * did when everything was one file.
 *
 * A CHUNK THAT FAILS TO ARRIVE opens the page afresh, once: see chunkRecovery.js.
 *
 * Echo is no longer imported here either: see echo.js for where it now loads.
 */
const pages = import.meta.glob('./Pages/**/*.vue')

watchLateChunks()

createInertiaApp({
    // M24 (D-223): a visitor who is not signed in reads the company's name in the tab, never the product's.
    title: (title) => tabTitle(title, Boolean(usePage().props?.auth?.user), appName),

    resolve: (name, page) => {
        const load = pages[`./Pages/${name}.vue`]

        if (!load) {
            // Fail loudly. A silent miss renders a blank screen, which during
            // a 165-screen build reads as "not written yet" rather than
            // "wired to the wrong name".
            throw new Error(`Inertia page not found: ./Pages/${name}.vue`)
        }

        // The address Inertia is opening — where any redirect landed — is the one to load afresh.
        return loadPage(load, page?.url ?? window.location.href)
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: {
        // The token, not its value. This used to be --navy-600's hex, written out,
        // on the belief that the colour is read before any stylesheet is parsed — but
        // Inertia only writes it into a <style> rule for the bar, and a custom
        // property resolves when that rule is applied to the element, against
        // :root in tokens.css. So the bar follows the token like everything else.
        color: 'var(--navy-600)',
    },
})
