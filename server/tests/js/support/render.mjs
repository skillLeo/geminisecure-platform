/**
 * Mount a component into memory, with Vue's own runtime, and read what it drew.
 *
 * A renderer built from `createRenderer` — the same core the browser build uses, pointed
 * at plain objects instead of the DOM. What a test can do with it is what a reader can:
 * read the text, find an element by its class, click it, type into it. Nothing here
 * knows about any particular component.
 */
import { createRenderer, h, nextTick, reactive } from 'vue'

class Node {
    constructor() {
        this.parent = null
    }
}

class Element extends Node {
    constructor(tag) {
        super()
        this.tag = tag
        this.children = []
        this.attrs = {}
        this.handlers = {}
        this.domListeners = new Map()
        this.value = ''
        this.selectedIndex = -1
        // What Vue's `v-show` writes to (`style.display`), so a page that uses it mounts.
        this.style = {}
    }

    // A page may focus a field on mount (Notices' title, DR-055); nothing here draws, so focus goes nowhere.
    focus() {}

    blur() {}

    addEventListener(type, listener) {
        if (!this.domListeners.has(type)) {
            this.domListeners.set(type, new Set())
        }

        this.domListeners.get(type).add(listener)
    }

    removeEventListener(type, listener) {
        this.domListeners.get(type)?.delete(listener)
    }

    getRootNode() {
        return globalThis.document
    }

    /** A <select>'s options, which Vue's `v-model` walks to mark the chosen one. */
    get options() {
        return this.children.filter((child) => child instanceof Element && child.tag === 'option')
    }

    get className() {
        return typeof this.attrs.class === 'string' ? this.attrs.class : ''
    }
}

class Text extends Node {
    constructor(text) {
        super()
        this.text = text
    }
}

class Comment extends Node {
    constructor(text) {
        super()
        this.text = text
    }
}

const detach = (child) => {
    if (child.parent) {
        const siblings = child.parent.children
        const at = siblings.indexOf(child)

        if (at >= 0) {
            siblings.splice(at, 1)
        }

        child.parent = null
    }
}

const { createApp } = createRenderer({
    createElement: (tag) => new Element(tag),
    createText: (text) => new Text(text),
    createComment: (text) => new Comment(text),
    setText: (node, text) => {
        node.text = text
    },
    setElementText: (el, text) => {
        el.children.forEach((child) => {
            child.parent = null
        })
        el.children = []

        if (text !== '') {
            const node = new Text(text)
            node.parent = el
            el.children.push(node)
        }
    },
    insert: (child, parent, anchor) => {
        detach(child)
        child.parent = parent
        const at = anchor ? parent.children.indexOf(anchor) : -1

        if (at >= 0) {
            parent.children.splice(at, 0, child)
        } else {
            parent.children.push(child)
        }
    },
    remove: (child) => detach(child),
    parentNode: (node) => node.parent,
    nextSibling: (node) => {
        if (!node.parent) {
            return null
        }

        const siblings = node.parent.children

        return siblings[siblings.indexOf(node) + 1] ?? null
    },
    patchProp: (el, key, previous, next) => {
        if (/^on[A-Z]/.test(key)) {
            const event = key.slice(2).toLowerCase()

            if (next == null) {
                delete el.handlers[event]
            } else {
                el.handlers[event] = next
            }

            return
        }

        if (key === 'value') {
            el.value = next ?? ''
        }

        if (next == null || next === false) {
            delete el.attrs[key]
        } else {
            el.attrs[key] = next
        }
    },
    setScopeId: () => {},
})

/** The text a reader sees under this node, with runs of white space collapsed. */
export function textOf(node) {
    const walk = (current) => {
        if (current instanceof Text) {
            return current.text
        }

        if (current instanceof Element) {
            return current.children.map(walk).join('')
        }

        return ''
    }

    return walk(node).replace(/\s+/g, ' ').trim()
}

export function findAll(node, predicate) {
    const found = []

    const walk = (current) => {
        if (current instanceof Element) {
            if (predicate(current)) {
                found.push(current)
            }

            current.children.forEach(walk)
        }
    }

    walk(node)

    return found
}

export const byClass = (name) => (el) => el.className.split(/\s+/).includes(name)

export const byTag = (tag) => (el) => el.tag === tag

/** Fire an event on an element and let it bubble, as a browser would. */
export function fire(el, type, init = {}) {
    let stopped = false

    const event = {
        type,
        target: el,
        currentTarget: el,
        defaultPrevented: false,
        stopPropagation: () => {
            stopped = true
        },
        preventDefault() {
            this.defaultPrevented = true
        },
        ...init,
    }

    for (let current = el; current && !stopped; current = current.parent) {
        event.currentTarget = current

        for (const listener of [...(current.domListeners?.get(type) ?? [])]) {
            listener(event)
        }

        const handler = current.handlers?.[type]

        if (Array.isArray(handler)) {
            handler.forEach((each) => each(event))
        } else if (typeof handler === 'function') {
            handler(event)
        }
    }

    return event
}

/**
 * Mount `component` with `props`; the returned `props` object is reactive, so writing
 * to it re-renders exactly as a parent passing new props would.
 */
export async function mount(component, props = {}) {
    const root = new Element('root')
    const state = reactive({ ...props })
    const app = createApp({ render: () => h(component, state) })

    app.config.warnHandler = () => {}
    app.mount(root)
    await nextTick()

    return {
        root,
        props: state,
        text: () => textOf(root),
        all: (predicate) => findAll(root, predicate),
        one: (predicate) => findAll(root, predicate)[0] ?? null,
        click: async (el) => {
            fire(el, 'click')
            await nextTick()
        },
        type: async (el, value) => {
            el.value = value
            fire(el, 'input')
            await nextTick()
        },
        unmount: () => app.unmount(),
    }
}

export { nextTick }
