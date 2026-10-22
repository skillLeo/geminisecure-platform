/**
 * THE CONSOLES' OWN SCRIPTS, RUN WITHOUT A BROWSER.
 *
 * Loaded with `node --import ./tests/js/support/register.mjs --test tests/js/`, before any
 * test file. It teaches Node the three things the Vite build knows and Node does not:
 *
 *   - a `.vue` file is compiled with Vue's own SFC compiler (the one Vite uses), template
 *     and `<script setup>` together, and its styles are dropped — nothing here draws;
 *   - an import written without its extension (`'../composables/useEstatePanic'`) finds
 *     the `.js` or `.vue` file, as Vite resolves it;
 *   - `@inertiajs/vue3` and `resources/js/echo.js` are replaced by the stand-ins in this
 *     folder, so a test drives the router, the page props and the socket itself;
 *   - `resources/js/live/socket.js` (the one Pusher the live client and Echo share) is
 *     replaced by `live-socket.mjs`, which alone may import the real module, for its
 *     options; and `resources/js/echo.js?real` reaches the real echo.js, so a test can
 *     see that Echo is handed that same instance rather than making its own.
 *
 * Then `env.mjs` gives the scripts the few browser globals they touch.
 */
import { registerHooks } from 'node:module'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { dirname, resolve as resolvePath } from 'node:path'
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'

const here = dirname(fileURLToPath(import.meta.url))

const STANDINS = {
    '@inertiajs/vue3': pathToFileURL(resolvePath(here, 'inertia.mjs')).href,
}

const ECHO = /[\\/]resources[\\/]js[\\/]echo(\.js)?$/

const LIVE_SOCKET = /[\\/]resources[\\/]js[\\/]live[\\/]socket(\.js)?$/
const LIVE_SOCKET_STANDIN = pathToFileURL(resolvePath(here, 'live-socket.mjs')).href

const withExtension = (specifier, parentURL) => {
    if (!parentURL || !/^\.\.?\//.test(specifier) || /\.[a-z]+$/i.test(specifier.split('/').pop())) {
        return null
    }

    const base = fileURLToPath(new URL(specifier, parentURL))

    for (const extension of ['.js', '.vue', '.mjs']) {
        if (existsSync(base + extension)) {
            return pathToFileURL(base + extension).href
        }
    }

    return null
}

let compiled = 0

const compileSfc = (url) => {
    const filename = fileURLToPath(url)
    const source = readFileSync(filename, 'utf8')
    const { descriptor, errors } = parse(source, { filename })

    if (errors.length > 0) {
        throw new Error(`${filename}: ${errors[0].message}`)
    }

    const id = `t${++compiled}`

    // No static hoisting: a stringified static node needs a real DOM to be inserted into.
    const compilerOptions = { hoistStatic: false }

    if (descriptor.scriptSetup || descriptor.script) {
        const script = compileScript(descriptor, {
            id,
            inlineTemplate: true,
            templateOptions: { compilerOptions },
        })

        return script.content
    }

    const template = compileTemplate({ source: descriptor.template.content, filename, id, compilerOptions })

    return `${template.code}\nexport default { render }\n`
}

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (STANDINS[specifier]) {
            return { url: STANDINS[specifier], shortCircuit: true }
        }

        const found = withExtension(specifier, context.parentURL)
        const result = found ? { url: found, shortCircuit: true } : nextResolve(specifier, context)

        if (result.url.startsWith('file:') && !result.url.endsWith('?real') && ECHO.test(fileURLToPath(result.url).replace(/\.js$/, ''))) {
            return { url: pathToFileURL(resolvePath(here, 'echo.mjs')).href, shortCircuit: true }
        }

        if (result.url.startsWith('file:') && context.parentURL !== LIVE_SOCKET_STANDIN && LIVE_SOCKET.test(fileURLToPath(result.url))) {
            return { url: LIVE_SOCKET_STANDIN, shortCircuit: true }
        }

        return result
    },

    load(url, context, nextLoad) {
        if (url.endsWith('.vue')) {
            // Vite always gives a component `import.meta.env`; Node gives none, so a component reading a VITE_ value
            // (SiteMap's tile server) reads its own default here, as a build with the value unset does.
            return { format: 'module', source: compileSfc(url).replaceAll('import.meta.env', '(import.meta.env ?? {})'), shortCircuit: true }
        }

        if (url.endsWith('.css')) {
            return { format: 'module', source: 'export default {}', shortCircuit: true }
        }

        return nextLoad(url, context)
    },
})

await import('./env.mjs')
