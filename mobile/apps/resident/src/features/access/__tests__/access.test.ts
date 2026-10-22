/**
 * J-ACC-1 — what a level may do, as the phone draws it (household invites, stage 2).
 *
 * The server is the rule; the phone only keeps from offering what would be refused. Nothing known = everything
 * drawn (an older server, no answer yet). Home's actions for a Dependent or a Helper lead with their own e-pass,
 * three to a row as ever. Who to ask is named when the server knows, and never invented when it does not (G9).
 */

import { homeActionsFor } from '@/features/home/homeActions'
import { quickActionWidth } from '@/features/home/quickActionLayout'
import { askSentence, keptBySentence, mayFrom, notForLevelSentence, primaryOf, type Access } from '../access'

const MAY_ALL = { household: true, household_edit: true, manage_members: false, visitor_passes: true, approvals: true, dues: true, payments: true, tickets: true, notices: true, meetings: true, elections: true, bookings: true }
const MAY_DEPENDENT = { ...MAY_ALL, household_edit: false, visitor_passes: false, approvals: false, dues: false, payments: false, meetings: false, elections: false, bookings: false }
const MAY_HELPER = { ...MAY_DEPENDENT, household: false, tickets: false, notices: false }

const access = (level: Access['level'], may: Access['may'], primary_name: string | null = 'Andrea Fletcher'): Access => ({ level, level_label: level[0]!.toUpperCase() + level.slice(1), is_primary: false, primary_name, levels_open: true, may })

describe('mayFrom', () => {
  it('is the server\'s answer for each level', () => {
    expect(mayFrom(access('member', MAY_ALL), 'dues')).toBe(true)
    expect(mayFrom(access('dependent', MAY_DEPENDENT), 'dues')).toBe(false)
    expect(mayFrom(access('dependent', MAY_DEPENDENT), 'notices')).toBe(true)
    expect(mayFrom(access('helper', MAY_HELPER), 'household')).toBe(false)
  })

  it('is optimistic when nothing is known: the server decides', () => {
    expect(mayFrom(null, 'dues')).toBe(true)
    expect(mayFrom(undefined, 'visitor_passes')).toBe(true)
  })
})

describe("Home's quick actions", () => {
  it('a Member sees the six the board draws', () => {
    expect(homeActionsFor(access('member', MAY_ALL), false)).toEqual(['invite', 'incident', 'maintenance', 'dues', 'meetings', 'notices'])
    expect(homeActionsFor(null, true)).toEqual(['invite', 'incident', 'maintenance', 'dues', 'amenity', 'notices'])
  })

  it('a Dependent leads with their e-pass, then only what the server allows them', () => {
    expect(homeActionsFor(access('dependent', MAY_DEPENDENT), false)).toEqual(['epass', 'incident', 'maintenance', 'notices'])
  })

  it('a Helper gets their e-pass and nothing the server would refuse', () => {
    expect(homeActionsFor(access('helper', MAY_HELPER), false)).toEqual(['epass'])
  })

  it('still three to a row on a narrow phone', () => {
    // Three tiles and their two gaps fit what the body leaves (quickActionLayout): the whole width live, since the
    // panic button moved to the header (M12 §2.2), and beside the board's panic button in the board's layout.
    expect(3 * quickActionWidth(360) + 18).toBeLessThanOrEqual(360 - 36 + 0.001)
    expect(3 * quickActionWidth(360, 'board') + 18).toBeLessThanOrEqual(360 - 36 - 72)
  })
})

describe('who to ask (G9)', () => {
  it("names the household's primary resident when the server knows them", () => {
    const helper = access('helper', MAY_HELPER)
    expect(askSentence(helper)).toBe('Ask Andrea Fletcher to change your access.')
    expect(keptBySentence(helper)).toBe("Your household's details are kept by Andrea Fletcher.")
    expect(notForLevelSentence(helper, 'dues')).toBe("Dues are for the household's Members. Your access is Helper. Ask Andrea Fletcher to change your access.")
  })

  it('never invents a name: null or absent reads as the role', () => {
    expect(primaryOf(access('helper', MAY_HELPER, null))).toBe("your household's primary resident")
    expect(askSentence(undefined)).toBe("Ask your household's primary resident to change your access.")
    expect(keptBySentence({ ...access('helper', MAY_HELPER), primary_name: undefined })).toBe("Your household's details are kept by your household's primary resident.")
  })
})
