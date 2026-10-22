/**
 * A stand-in for `@inertiajs/vue3`: the page's props are a reactive object a test writes
 * to, and every visit the scripts make is recorded — with its options, so a test can
 * answer it the way the server would (`onSuccess`, `onError`, `onFinish`).
 */
import { defineComponent, h, reactive } from 'vue'

export const page = reactive({ url: '/', props: {} })

export const visits = []

const visit = (method) => (url, data = {}, options = {}) => {
    const call = { method, url, data, options }

    // `router.reload(options)` has no url or data.
    if (method === 'reload') {
        call.url = page.url
        call.data = {}
        call.options = url ?? {}
    }

    visits.push(call)

    return call
}

/*
 * `replaceProp` is a CLIENT-SIDE prop update, not a visit — as the real router does it,
 * with a dot path and either a value or a function of the current value — so it is kept
 * apart from `visits`: a test that asserts "no reload" is not fooled by it.
 */
export const replaced = []

const pathGet = (object, path) => path.split('.').reduce((at, key) => (at == null ? undefined : at[key]), object)

const pathSet = (object, path, value) => {
    const keys = path.split('.')
    const last = keys.pop()
    const parent = keys.reduce((at, key) => {
        if (at[key] === null || typeof at[key] !== 'object') {
            at[key] = {}
        }

        return at[key]
    }, object)

    parent[last] = value
}

/* `router.on(type, callback)` as the real one: a listener, and the function that removes it. */
const routerListeners = new Map()

/** Fire a router event, as the real router would after a visit (`navigate`, `before`, …). */
export function fireRouterEvent(type, detail = {}) {
    for (const callback of [...(routerListeners.get(type) ?? [])]) {
        callback({ detail })
    }
}

export const router = {
    get: visit('get'),
    post: visit('post'),
    visit: visit('visit'),
    reload: visit('reload'),

    replaceProp(name, value) {
        const next = typeof value === 'function' ? value(pathGet(page.props, name), page.props) : value
        replaced.push({ name, value: next })
        pathSet(page.props, name, next)
    },

    on(type, callback) {
        if (!routerListeners.has(type)) {
            routerListeners.set(type, new Set())
        }

        routerListeners.get(type).add(callback)

        return () => routerListeners.get(type)?.delete(callback)
    },
}

/*
 * `http.onRequest(handler)` as the real one: a handler every Inertia request passes through
 * before it is sent. `processRequest` is how a test sends one.
 */
const requestHandlers = []

export const http = {
    onRequest(handler) {
        requestHandlers.push(handler)

        return () => {
            const at = requestHandlers.indexOf(handler)

            if (at >= 0) {
                requestHandlers.splice(at, 1)
            }
        }
    },

    async processRequest(config) {
        let result = config

        for (const handler of [...requestHandlers]) {
            result = await handler(result)
        }

        return result
    },
}

/** Clear the page and the recorded visits between tests. */
export function resetInertia(props = {}, url = '/') {
    visits.length = 0
    replaced.length = 0
    page.url = url

    for (const key of Object.keys(page.props)) {
        delete page.props[key]
    }

    Object.assign(page.props, props)
}

export function usePage() {
    return page
}

export const Link = defineComponent({
    name: 'Link',
    props: { href: { type: String, default: '' } },
    emits: ['click'],
    setup(props, { slots, attrs, emit }) {
        return () => h('a', { ...attrs, href: props.href, onClick: (event) => emit('click', event) }, slots.default?.())
    },
})

export const Head = defineComponent({
    name: 'Head',
    props: { title: { type: String, default: '' } },
    setup() {
        return () => null
    },
})

/*
 * As Inertia's own form does: it posts the fields as they are NOW, a refusal fills
 * `errors`, and `clearErrors()` / `reset()` put it back. (It used to post the
 * initial values, which no real form ever does, and had no clearErrors at all.)
 */
export function useForm(initial = {}) {
    const keys = Object.keys(initial)
    // As Inertia's own form: what is posted passes through the last transform() given (New charge uses one).
    let transformer = (data) => data

    const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        data() {
            return Object.fromEntries(keys.map((key) => [key, form[key]]))
        },
        transform(callback) {
            transformer = callback

            return form
        },
        post(url, options = {}) {
            return router.post(url, transformer(form.data()), {
                ...options,
                onError: (errors) => {
                    form.errors = { ...errors }
                    options.onError?.(errors)
                },
            })
        },
        clearErrors() {
            form.errors = {}
        },
        reset() {
            for (const key of keys) {
                form[key] = initial[key]
            }
        },
    })

    return form
}
