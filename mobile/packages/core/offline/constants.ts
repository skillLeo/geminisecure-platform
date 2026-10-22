/** Storage keys under this prefix are the offline outbox. A REVOKED session keeps them; a deliberate sign-out clears them. */
export const OUTBOX_PREFIX = 'outbox:'
