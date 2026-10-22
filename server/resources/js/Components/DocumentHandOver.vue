<script setup>
import { onBeforeUnmount, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { toast } from '../lib/toasts.js'

/**
 * A DOCUMENT ASKED FOR IS HANDED OVER (the user, M56 §5: "View agenda shows a green banner and no document ever appears.
 * This is the same fault as View receipt on Bills. Fix it once.").
 *
 * Every document is written by a worker, so the press can only say "Preparing the agenda". Nothing then waited for the
 * file: a finished agenda sat on the meeting's own page under "Papers issued", where nobody pressing on the register
 * would look. This lives in the console's shell, so every screen that asks for a document (an agenda, minutes, a
 * receipt, a remittance, a statement, a certificate) waits for it the same way: it asks where the document is every two
 * seconds, starts the download the moment it is ready, and says in one line if it could not be produced.
 *
 * ONE MESSAGE, IN PLACE, WITH AN END (M95 §2.6: "The remittance advice message currently appears as a banner and a
 * toast at the same time"). It is one toast, keyed, that changes as the document does: preparing, then downloading, or
 * could not be produced, or taking longer than it should. The flash that asked for the document is not said again.
 */
const page = usePage()

const KEY = 'document'

let timer = null
let tries = 0

const stop = () => {
    if (timer !== null) {
        clearTimeout(timer)
        timer = null
    }
}

const hand = (doc) => {
    stop()
    tries = 0

    const what = doc.label.toLowerCase()

    // Up until the document is ready or has failed: not a message that closes itself on a clock.
    toast(`Preparing the ${what}. It will download in a moment.`, { tone: 'info', key: KEY, sticky: true })

    const ask = async () => {
        tries += 1

        try {
            const response = await fetch(doc.status_url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            const state = response.ok ? await response.json() : null

            if (state?.ready) {
                toast(`The ${what} is downloading.`, { tone: 'success', key: KEY })
                const link = document.createElement('a')
                link.href = doc.download_url
                link.rel = 'noopener'
                document.body.appendChild(link)
                link.click()
                link.remove()

                return
            }

            if (state?.failed) {
                toast(`The ${what} could not be produced: ${state.reason}`, { tone: 'error', key: KEY })

                return
            }
        } catch {
            // A dropped request is asked again; only the clock gives up.
        }

        if (tries >= 45) {
            toast(`The ${what} is taking longer than it should. It will be under "Papers issued" when it is ready.`, { tone: 'warning', key: KEY })

            return
        }

        timer = setTimeout(ask, 2000)
    }

    timer = setTimeout(ask, 1200)
}

watch(
    () => page.props.flash?.document,
    (doc) => {
        if (doc?.id && doc.status_url && typeof document !== 'undefined') {
            hand(doc)
        }
    },
    { immediate: true },
)

onBeforeUnmount(stop)
</script>

<template>
    <!-- Its words are the toast stack's (M95 §2): this only waits for the document. -->
</template>
