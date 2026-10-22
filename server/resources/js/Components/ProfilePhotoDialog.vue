<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import ConsoleDialog from './ConsoleDialog.vue'
import ConsoleAvatar from './ConsoleAvatar.vue'

/**
 * "Profile picture" — from the account menu of either console (M12 §4.8).
 *
 * THE PICTURE IS MADE HERE, NOT UPLOADED AS CHOSEN. The chosen photo is drawn onto a 256-pixel square (the middle of
 * it, so a face stays a face) and saved as a JPEG from that canvas. That keeps it small, and it keeps nothing the
 * original carried inside it — a phone photo's EXIF can hold the GPS position it was taken at, and a picture shown
 * beside somebody's name must not say where they live. The server redraws it again when it arrives (ConsolePhotos),
 * so a picture sent some other way is kept clean too.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    user: { type: Object, default: null },
    root: { type: String, default: '' },
})

const emit = defineEmits(['close'])

/** The saved picture's side, in pixels: twice the largest avatar a shell draws, for a sharp one on a dense screen. */
const SIZE = 256

/** The largest photo that may be chosen (M32 F9: "at most 5 MB"): ConsolePhotos::MAX_KB, in bytes. */
const MAX_BYTES = 5 * 1024 * 1024

const blob = ref(null)
const preview = ref(null)
const problem = ref(null)
const busy = ref(false)

const reset = () => {
    if (preview.value) URL.revokeObjectURL(preview.value)
    blob.value = null
    preview.value = null
    problem.value = null
}

watch(() => props.open, (open) => { if (!open) reset() })
onBeforeUnmount(reset)

/** The middle square of the photo, drawn at SIZE × SIZE, as a JPEG. */
const squareJpeg = (image) =>
    new Promise((resolve) => {
        const side = Math.min(image.naturalWidth, image.naturalHeight)
        const canvas = document.createElement('canvas')
        canvas.width = SIZE
        canvas.height = SIZE
        const g = canvas.getContext('2d')
        // A JPEG has no transparency: what shows through a see-through picture is white, by name, not a hex literal.
        g.fillStyle = 'white'
        g.fillRect(0, 0, SIZE, SIZE)
        g.drawImage(image, (image.naturalWidth - side) / 2, (image.naturalHeight - side) / 2, side, side, 0, 0, SIZE, SIZE)
        canvas.toBlob(resolve, 'image/jpeg', 0.86)
    })

const choose = (event) => {
    reset()
    const file = event.target.files?.[0]
    event.target.value = ''

    if (!file) return
    if (!/^image\//.test(file.type)) {
        problem.value = 'That file is not a picture. Choose a JPEG, PNG or WebP image.'
        return
    }
    if (file.size > MAX_BYTES) {
        problem.value = 'That picture is larger than 5 MB. Choose a smaller one.'
        return
    }

    const url = URL.createObjectURL(file)
    const image = new Image()
    image.onload = async () => {
        URL.revokeObjectURL(url)
        const made = await squareJpeg(image)
        if (!made) {
            problem.value = 'That picture could not be prepared in this browser. Choose a JPEG or PNG.'
            return
        }
        blob.value = made
        preview.value = URL.createObjectURL(made)
    }
    image.onerror = () => {
        URL.revokeObjectURL(url)
        // An iPhone's HEIC photo opens in Safari only: say what to do rather than fail silently.
        problem.value = 'This browser cannot open that picture. Choose a JPEG or PNG (an iPhone photo can be shared as JPEG).'
    }
    image.src = url
}

const save = () => {
    if (!blob.value || busy.value) return
    busy.value = true
    problem.value = null
    router.post(`${props.root}/account/photo`, { photo: new File([blob.value], 'profile.jpg', { type: 'image/jpeg' }) }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => { problem.value = errors.photo ?? 'The picture could not be saved. Try again.' },
        onFinish: () => { busy.value = false },
    })
}

const remove = () => {
    if (busy.value) return
    busy.value = true
    problem.value = null
    router.delete(`${props.root}/account/photo`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: () => { problem.value = 'The picture could not be removed. Try again.' },
        onFinish: () => { busy.value = false },
    })
}

const hasPhoto = computed(() => Boolean(props.user?.photo_version))
</script>

<template>
    <ConsoleDialog
        :open="open"
        title="Profile picture"
        confirm-label="Save picture"
        busy-label="Saving…"
        :busy="busy"
        :confirm-blocked-reason="blob ? null : 'Choose a picture first.'"
        :error="problem"
        @confirm="save"
        @cancel="emit('close')"
    >
        <div class="pp-body">
            <div class="pp-preview">
                <img v-if="preview" :src="preview" alt="Your new profile picture" class="pp-img" />
                <ConsoleAvatar v-else box="pp-avatar" :user="user" :root="root" />
            </div>

            <div class="pp-side">
                <label class="pp-label" for="pp-file">Choose a photo</label>
                <input id="pp-file" type="file" accept="image/jpeg,image/png,image/webp" @change="choose" />
                <p class="pp-note">
                    Shown beside your name in the consoles. At most 5 MB. It is cut to a square and made small here, and
                    saved without the photo's hidden details — such as where it was taken.
                </p>
                <button v-if="hasPhoto && !preview" type="button" class="text-link-sm pp-remove" :disabled="busy" @click="remove">
                    Remove my picture
                </button>
            </div>
        </div>
    </ConsoleDialog>
</template>

<style scoped>
/* Authored: no board draws this dialog. The consoles' own tokens. */
.pp-body {
    display: flex;
    gap: 18px;
    align-items: flex-start;
}

.pp-preview {
    flex: 0 0 auto;
}

.pp-img,
.pp-preview :deep(.pp-avatar) {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--navy-100);
    color: var(--navy-700);
    font-family: 'Poppins', sans-serif;
    font-size: 28px;
    font-weight: 700;
    object-fit: cover;
    border: 2px solid var(--navy-200);
}

.pp-side {
    display: grid;
    gap: 8px;
    min-width: 0;
}

.pp-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--navy-900);
}

.pp-note {
    margin: 0;
    font-size: 11.5px;
    line-height: 1.55;
    color: var(--slate-600);
}

.pp-remove {
    justify-self: start;
    color: var(--red-700);
}
</style>
