/**
 * The handful of browser globals the consoles' scripts touch, and no more.
 *
 * `document` takes listeners and a title, `window.localStorage` keeps strings, and
 * `AudioContext` is a stand-in whose `state` a test sets — so "the browser is refusing
 * to play sound" is a condition a test can create rather than one it has to hope for.
 */

class Listeners {
    constructor() {
        this.map = new Map()
    }

    add(type, handler, options) {
        if (!this.map.has(type)) {
            this.map.set(type, new Set())
        }

        const entry = { handler, once: typeof options === 'object' && options?.once === true }
        this.map.get(type).add(entry)
    }

    remove(type, handler) {
        for (const entry of this.map.get(type) ?? []) {
            if (entry.handler === handler) {
                this.map.get(type).delete(entry)
            }
        }
    }

    dispatch(type, event = {}) {
        for (const entry of [...(this.map.get(type) ?? [])]) {
            if (entry.once) {
                this.map.get(type).delete(entry)
            }

            entry.handler(event)
        }
    }

    count(type) {
        return this.map.get(type)?.size ?? 0
    }
}

const documentListeners = new Listeners()

// `useWireframe` puts the board's class on <body>, and reads what is there.
const bodyClasses = new Set()

// Vue's `v-model` asks whether an input's root node is a Document before it writes the value.
class Document {}
class ShadowRoot {}

globalThis.Document = Document
globalThis.ShadowRoot = ShadowRoot

globalThis.document = Object.assign(new Document(), {
    title: 'Console',
    body: {
        classList: {
            add: (name) => bodyClasses.add(name),
            remove: (name) => bodyClasses.delete(name),
            contains: (name) => bodyClasses.has(name),
            [Symbol.iterator]: () => bodyClasses.values(),
        },
    },
    hidden: false,
    activeElement: null,
    addEventListener: (type, handler, options) => documentListeners.add(type, handler, options),
    removeEventListener: (type, handler) => documentListeners.remove(type, handler),
    dispatchEvent: (type, event) => documentListeners.dispatch(type, event),
    listenerCount: (type) => documentListeners.count(type),

    // Vue's DOM runtime keeps a <template> for static content at import; nothing here uses it.
    createElement: () => ({ content: {}, setAttribute() {}, appendChild() {} }),
    createElementNS: () => ({ setAttribute() {} }),
})

const storage = () => {
    const values = new Map()

    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
        clear: () => values.clear(),
    }
}

/** Every AudioContext the scripts made, newest last, so a test can reach the one in use. */
export const audioContexts = []

/** The state a NEW context starts in: 'suspended' is a browser refusing sound until a gesture. */
export const audio = { initialState: 'running', started: 0 }

class FakeAudioContext {
    constructor() {
        this.state = audio.initialState
        this.currentTime = 0
        this.destination = {}
        this.onstatechange = null
        audioContexts.push(this)
    }

    resume() {
        if (audio.refuseResume) {
            return Promise.reject(new Error('not allowed'))
        }

        this.state = 'running'
        this.onstatechange?.()

        return Promise.resolve()
    }

    close() {
        this.state = 'closed'

        return Promise.resolve()
    }

    createOscillator() {
        return {
            type: 'sine',
            frequency: { setValueAtTime() {} },
            connect() {},
            start: () => {
                audio.started++
            },
            stop() {},
        }
    }

    createGain() {
        return {
            gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} },
            connect() {},
        }
    }
}

globalThis.window = globalThis

Object.defineProperty(globalThis, 'localStorage', { value: storage(), configurable: true, writable: true })
Object.defineProperty(globalThis, 'sessionStorage', { value: storage(), configurable: true, writable: true })

globalThis.AudioContext = FakeAudioContext
