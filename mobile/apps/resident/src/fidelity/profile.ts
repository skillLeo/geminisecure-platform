/**
 * The profile boards' own data (resident-app-17 Profile · -32 Add Household Member). The household is served
 * only in web fidelity mode, behind `isFidelity`; board 32's sample words are ALSO the grey placeholders of its
 * empty boxes, live (as Invite visitor's are: the user, 7 October, "no placeholder in any input as the design"),
 * and board 17's vehicle is the example in Add a vehicle's.
 *
 * The household itself is in the SERVER's shape, so the board's state goes through the same code a
 * real household does (its initials, its unit line, its vehicle and contact lines). What only the
 * board has is kept apart under `board`:
 *   -17  a PET ("Milo · dog") beside the vehicle, and member pills that name an access level
 *        ("Member", "Dependent"). The server keeps no pets; it does keep an access level now, and the
 *        live pill is that level — the fixture's pills stay as the board draws them.
 *   -32  a free-text relationship ("Brother" — the server takes one of seven values) and the phone box's
 *        placeholder. Every other word of board 32 is drawn by the screen itself, live as in fidelity.
 */

export const profileFixture = {
  me: {
    account: { id: 1, status: 'active', channel: 'email', destination_hint: 'a•••@example.com', full_name: 'Andrea Fletcher' },
    estate: { id: 'phoenixpark', name: 'Phoenix Park Village 1' },
    claim: null,
    unit: { id: 47, reference: 'Lot 47', phase: 'Phase 2' },
    household: { id: 1, name: 'Fletcher' },
    resident: { id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: '876 555 0148', email: 'andrea.fletcher@example.com' },
  },
  members: [
    { id: 1, full_name: 'Andrea Fletcher', relationship: 'owner', is_primary: true, status: 'verified', phone: '876 555 0148', email: 'andrea.fletcher@example.com', alerts_to_primary: false },
    { id: 2, full_name: 'Jason Fletcher', relationship: 'spouse', is_primary: false, status: 'verified', phone: null, email: null, alerts_to_primary: false },
    { id: 3, full_name: 'Chloe Fletcher', relationship: 'child', is_primary: false, status: 'verified', phone: null, email: null, alerts_to_primary: false },
  ],
  vehicles: [{ id: 1, plate: 'PC 4471', make: 'Honda', model: 'CR-V', colour: 'Grey' }],
  contacts: [{ id: 1, name: 'Marlon Fletcher', relationship: 'Brother', phone: '876 555 0192', email: null }],
  /** What only the board has. */
  board: {
    vehiclesTitle: 'Vehicles & pets',
    pet: 'Milo · dog',
    contactsTitle: 'Emergency contact',
    memberTags: { '1': 'Primary', '2': 'Member', '3': 'Dependent' } as Record<string, string>,
  },
} as const

export const addMemberFixture = {
  fullName: 'Marlon Fletcher',
  /** The server's value behind the board's "Brother". */
  relationship: 'relative',
  /**
   * NOT ON THE BOARD, which draws no email box: the example in the one the build requires (M47 §15m), in the board's
   * person's name.
   */
  email: 'marlon.fletcher@gmail.com',
  /** What only the board has. */
  board: {
    relationshipLabel: 'Brother',
    phonePlaceholder: '876 555 0192',
  },
} as const
