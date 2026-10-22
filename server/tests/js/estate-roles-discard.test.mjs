/**
 * One role's permissions, a page of its own: `Pages/Estate/Settings/RolePermissions.vue` (Hassam, 12 Oct 2026; M62 B6,
 * DR-025; Law 1).
 *
 *   The editor was a modal on the roles page; it is a page now (a long table read across is never a modal). Its
 *   "Discard changes" reads as it always did: disabled, with the reason, until something has changed, then it puts back
 *   what was saved. The foot carries a running count where "Nothing has been changed yet" was, and the review names
 *   every single change in words before anything is saved: only the review's button posts.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: RolePermissions } = await import('../../resources/js/Pages/Estate/Settings/RolePermissions.vue')

const EDITOR = {
    can_edit: true,
    modules: [{ key: 'residents', label: 'Residents', acts: [] }],
    levels: [
        { key: 'none', label: 'None', verbs: [] },
        { key: 'view', label: 'View', verbs: ['view'] },
        { key: 'full', label: 'Full', verbs: ['view', 'create', 'update', 'delete', 'export'] },
    ],
    verbs: ['view', 'create', 'update', 'delete', 'export', 'approve'].map((key) => ({ key, label: key[0].toUpperCase() + key.slice(1) })),
    grantable: { residents: { view: true, create: true, update: true, delete: true, export: true, approve: true } },
    grantable_reason: 'You can only give a role, or take from it, what your own role holds.',
    position_rule: { reports: 'reports', money: [], warning: '' },
}

const ROLE = {
    id: 5,
    name: 'estate.treasurer',
    label: 'Treasurer',
    holders: 1,
    is_shared: false,
    can_edit_permissions: true,
    cells: { residents: { level: 'view', verbs: ['view'], can_approve: false, acts: [] } },
}

let user = 65000

const open = async () => {
    resetInertia({ auth: { user: { id: ++user, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {} }, '/settings/roles/5/permissions')
    const view = await mount(RolePermissions, {
        estate: { name: 'Phoenix Park' },
        role: ROLE,
        holderNames: ['Marcia Bennett'],
        editor: EDITOR,
        invariant_note: 'Closed to the Property Manager by platform invariant.',
        rolesHref: '/settings/roles',
        saveHref: '/settings/roles/5/permissions',
    })
    await flush(nextTick)

    const button = (words) => view.one((el) => el.tag === 'button' && textOf(el).trim().startsWith(words))
    const isDisabled = (el) => el.attrs.disabled !== undefined && el.attrs.disabled !== false

    const chooseFull = async () => {
        const level = view.one((el) => el.tag === 'select' && [...(el.children ?? [])].some((o) => textOf(o).trim() === 'Full'))
        assert.equal(level === null, false, "the module's level box")
        // A select's change, as a browser fires it when a choice is made.
        level.value = 'full'
        fire(level, 'change')
        await nextTick()
    }

    return { view, button, isDisabled, chooseFull }
}

describe('One role\'s permissions, a page of its own', () => {
    it('says who holds the role, and keeps Discard changes greyed with its reason while nothing has changed', async () => {
        const { view, button, isDisabled } = await open()

        try {
            assert.equal(view.text().includes('Held by Marcia Bennett.'), true, 'who holds it, by name')
            assert.equal(view.text().includes('0 changes made'), true, 'the running count')
            assert.equal(isDisabled(button('Discard changes')), true)
            assert.equal(button('Discard changes').attrs.title, 'Change a permission first.')
            assert.equal(isDisabled(button('Review changes')), true, 'nothing to review yet')
        } finally {
            view.unmount()
        }
    })

    it('counts every change, names each one in the review, and posts only from the review', async () => {
        const { view, button, isDisabled, chooseFull } = await open()

        try {
            await chooseFull()

            // View to Full gives create, update, delete and export: four changes.
            assert.equal(view.text().includes('4 changes made'), true, 'the running count')
            assert.equal(isDisabled(button('Discard changes')), false, 'enabled once something changed')
            assert.equal(visits.length, 0, 'choosing posts nothing')

            await view.click(button('Review 4 changes'))
            const review = view.text()

            for (const verb of ['create', 'update', 'delete', 'export']) {
                assert.equal(review.includes(`Treasurer gains ${verb} on Residents`), true, `the review names ${verb}`)
            }

            assert.equal(visits.length, 0, 'the review posts nothing by itself')

            await view.click(button('Save these 4 changes'))
            assert.equal(visits.at(-1).url, '/settings/roles/5/permissions', 'only the review\'s button posts')
        } finally {
            view.unmount()
        }
    })

    it('puts back what was saved when Discard changes is pressed', async () => {
        const { view, button, isDisabled, chooseFull } = await open()

        try {
            await chooseFull()
            await view.click(button('Discard changes'))

            assert.equal(isDisabled(button('Discard changes')), true, 'disabled again: what was saved is back')
            assert.equal(view.text().includes('0 changes made'), true)
        } finally {
            view.unmount()
        }
    })
})
