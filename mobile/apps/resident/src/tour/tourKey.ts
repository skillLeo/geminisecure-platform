/**
 * WHERE THE TOUR'S RECORD LIVES in the key-value store: `jsonStore`'s full key for ('tour', 'v1').
 *
 * A module of its own so the store can keep it through a session that ends by itself (the thirty-minute idle window,
 * a revocation) without importing the tour's hooks, which import the store. A sign-out the person chose still clears
 * it with everything else.
 */
export const TOUR_STORAGE_NAME = 'tour'
export const TOUR_STORAGE_VERSION = 'v1'
export const TOUR_STORAGE_KEY = `${TOUR_STORAGE_VERSION}:${TOUR_STORAGE_NAME}`
