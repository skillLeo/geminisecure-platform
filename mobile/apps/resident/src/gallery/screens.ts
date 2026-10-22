/**
 * DEV ONLY — what the gallery lists (M45 §3): every screen the client's design draws, and every screen the app has
 * that the design does not (M43 §4), each with the states it can be put in. Required only by the gallery route in a
 * gallery or fidelity build; no other bundle carries this file.
 */

/** A marker the release-bundle proof looks for (tools/design/gallery-proof.mjs): it must never be found in one. */
export const GALLERY_MARKER = 'gs-design-gallery-v1'

/** M43 §3. A screen draws the design's own state today; the others are wired screen by screen as each is rebuilt. */
export const STATES = ['design', 'loading', 'empty', 'error', 'offline', 'permission'] as const
export type GalleryState = (typeof STATES)[number]

export type GalleryScreen = {
  /** The fidelity id the screen's fixtures answer to. */
  id: string
  title: string
  route: string
  /** The states this screen can draw now. */
  states: readonly GalleryState[]
}

const design = (n: number, title: string, route: string): GalleryScreen => ({ id: `resident-app-${String(n).padStart(2, '0')}`, title, route, states: ['design'] })
const extra = (slug: string, title: string, route: string): GalleryScreen => ({ id: `resident-extra-${slug}`, title, route, states: ['design'] })

/** The forty screens of the design (the boards' own numbers; 01 is the splash). */
export const DESIGN_SCREENS: readonly GalleryScreen[] = [
  design(1, 'Splash', '/'),
  design(2, 'Sign Up', '/(auth)/sign-up'),
  design(3, 'Log In', '/(auth)/log-in'),
  design(4, 'Forgot Password', '/(auth)/forgot'),
  design(5, 'Home', '/(tabs)'),
  design(6, 'Panic Alert', '/panic'),
  design(7, 'Invite Visitor', '/visitors/invite'),
  design(8, 'Visitor Tracking', '/(tabs)/visitors'),
  design(9, 'Dues Statement', '/(tabs)/dues'),
  design(10, 'Payment', '/dues/pay'),
  design(11, 'Maintenance Request', '/maintenance/new'),
  design(12, 'Notices', '/(tabs)/notices'),
  design(13, 'Meeting Lobby', '/meetings/1'),
  design(14, 'Report Incident', '/incident/new'),
  design(15, 'Vote — Ballot A', '/elections/1/ballot-a'),
  design(16, 'Vote Receipt', '/elections/1/receipt'),
  design(17, 'Profile', '/(tabs)/profile'),
  design(18, 'Settings', '/settings'),
  design(19, 'Security Settings', '/settings/security'),
  design(20, 'Notification Settings', '/settings/notifications'),
  design(21, 'Book an Amenity', '/amenities'),
  design(22, 'Gazebo Checkout', '/amenities/1'),
  design(23, 'Live Meeting', '/meetings/1/live'),
  design(24, 'Vote — Ballot B', '/elections/1/ballot-b'),
  design(25, 'Visitor Approval', '/visitors/approval/1'),
  design(26, 'Maintenance Status', '/maintenance/1'),
  design(27, 'Incident Status', '/incident/1'),
  design(28, 'Election Results', '/elections/1/results'),
  design(29, 'Permissions', '/permissions'),
  design(30, 'Payment Success', '/dues/success'),
  design(31, 'Booking Confirmed', '/amenities/confirmed'),
  design(32, 'Add Household Member', '/household/add'),
  design(33, 'Privacy & Data', '/settings/privacy'),
  design(34, 'Review Your Vote', '/elections/1/review'),
  design(35, 'Add a Card', '/dues/add-card'),
  design(36, 'Notice Detail', '/notices/1'),
  design(37, 'My Bookings', '/amenities/bookings'),
  design(38, 'Language & Accessibility', '/settings/language'),
  design(39, 'Help & Support', '/settings/help'),
  design(40, 'Payments & AutoPay', '/dues/autopay'),
]

/** Screens the app has and the design does not draw (M43 §4): restyled in the design's language, never deleted. */
export const EXTRA_SCREENS: readonly GalleryScreen[] = [
  extra('code', 'Sign-in code', '/(auth)/code'),
  extra('claim-unit', 'Claim your unit', '/(auth)/claim-unit'),
  extra('pending', 'Waiting for approval', '/(auth)/pending'),
  extra('restore', 'Welcome back', '/(auth)/restore'),
  extra('deleted', 'Account deleted', '/(auth)/deleted'),
  extra('requests', 'All requests', '/requests'),
  extra('meetings', 'All meetings', '/meetings'),
  extra('pass', 'Visitor pass / My e-Pass', '/visitors/pass/1'),
  extra('contact', 'Add emergency contact', '/household/contact'),
  extra('details', 'Edit your details', '/household/details'),
  extra('vehicle', 'Add vehicle', '/household/vehicle'),
  extra('pet', 'Add pet', '/household/pet'),
  extra('saved', 'Saved copies', '/settings/saved'),
  extra('waiting', 'Waiting to send', '/settings/waiting'),
  extra('delete-account', 'Delete account', '/settings/delete-account'),
  extra('test-pay', 'Test payment (goes with M42 E6)', '/dues/test-pay'),
]

export const ALL_SCREENS: readonly GalleryScreen[] = [...DESIGN_SCREENS, ...EXTRA_SCREENS]
