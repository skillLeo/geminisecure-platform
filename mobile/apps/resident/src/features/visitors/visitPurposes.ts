/**
 * What a visit is for — the "Purpose of visit" dropdown on Invite visitor (M42 §5 #6).
 *
 * DATA, NOT CODE (the user, 7 October): the screen reads this list and draws nothing of its own, so changing the
 * choices is an edit here and nowhere else. The words chosen are what is sent as the pass's `purpose`, and what the
 * guard reads at the gate. Moving the list to the server is a later job.
 */
export const VISIT_PURPOSES: readonly string[] = [
  'Family visit',
  'Friend',
  'Delivery',
  'Contractor or tradesperson',
  // A driver coming to collect or drop somebody — in the arrival dropdown's own words (Ride in, M47 §7.1; M49 §5.3).
  'Ride pickup or drop-off',
  'Domestic helper',
  'Other',
]
