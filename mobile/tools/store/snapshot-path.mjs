/**
 * Where each app's permission snapshot lives — one answer for the audit that writes it and the gate that reads it.
 *
 * The Resident App goes to the stores, so its snapshot is part of the store pack, in `store/manifests/`. The Guard App
 * is distributed privately and listed in no store (M25), so nothing of it may be in `store/`: its snapshot lives with
 * the rest of its private distribution, in `distribution/guard/`. Both are written and checked exactly as before.
 */

import path from 'node:path'

export const STORE_APPS = ['resident']

/** @param {string} root the repository root  @param {string} app 'guard' | 'resident' */
export function permissionSnapshot(root, app) {
  return STORE_APPS.includes(app)
    ? path.join(root, 'store', 'manifests', `${app}.permissions.json`)
    : path.join(root, 'distribution', app, `${app}.permissions.json`)
}
