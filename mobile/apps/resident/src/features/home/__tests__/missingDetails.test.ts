/**
 * M18C §5 — an older record is asked, never locked out: the words, and "ask again next time".
 */

import { dismissMissingDetails, missingDetailsDismissed, missingDetailsPrompt, missingOf, resetMissingDetails } from '../missingDetails'

afterEach(() => resetMissingDetails())

it('asks for both, one, or nothing: in the client’s words', () => {
  expect(missingDetailsPrompt(['phone', 'email'])).toEqual({
    title: 'Add your phone number and email',
    body: 'We need a phone number and an email so we can reach you in an emergency. Nothing is held back until you do.',
    action: 'Add them now',
    missing: ['phone', 'email'],
  })
  expect(missingDetailsPrompt(['phone'])?.body).toBe('We need a phone number so we can reach you in an emergency. Nothing is held back until you do.')
  expect(missingDetailsPrompt(['email'])).toMatchObject({ title: 'Add your email', action: 'Add it now' })
  expect(missingDetailsPrompt([])).toBeNull()
})

it('reads the server’s list defensively: an older server, or anything it does not know, asks nothing', () => {
  expect(missingDetailsPrompt(undefined)).toBeNull()
  expect(missingDetailsPrompt('phone')).toBeNull()
  expect(missingOf(['email', 'fax', 'phone', 'phone'])).toEqual(['phone', 'email'])
})

it('once dismissed, stays away for this launch, and is asked afresh of the next person on the phone', () => {
  expect(missingDetailsDismissed(7)).toBe(false)
  dismissMissingDetails(7)
  expect(missingDetailsDismissed(7)).toBe(true)
  expect(missingDetailsDismissed(8)).toBe(false)
  expect(missingDetailsDismissed(null)).toBe(false)
})
