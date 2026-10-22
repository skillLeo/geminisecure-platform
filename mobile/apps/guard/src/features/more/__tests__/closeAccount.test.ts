/**
 * The Guard App's answer to "delete my account".
 *
 * This app issues no accounts and holds an EMPLOYMENT record, so it must not pretend to delete one.
 * What it must do is be findable, say who can close the account, and do the part that is the
 * officer's — take the app off this phone.
 */

import { HOW, LEGAL_LEAD, REMOVE_FROM_PHONE, TITLE, WHOSE_ACCOUNT } from '../closeAccount'

describe('what an officer is told', () => {
  it('says whose account it is, and why the app cannot delete it', () => {
    const text = WHOSE_ACCOUNT.join(' ')
    expect(text).toMatch(/issued by your employer/i)
    expect(text).toMatch(/employment (and tax )?law/i)
    expect(text).toMatch(/not something this app can delete/i)
  })

  it('names who CAN close it, rather than leaving the officer nowhere', () => {
    const text = HOW.join(' ')
    expect(text).toMatch(/supervisor|the office/i)
    expect(text).toMatch(/lost or stolen/i)
  })

  it('offers the one thing that really is the officer\'s, and says what it costs', () => {
    expect(REMOVE_FROM_PHONE.title).toMatch(/this phone/i)
    expect(REMOVE_FROM_PHONE.body).toMatch(/signs you out/i)
    expect(REMOVE_FROM_PHONE.body).toMatch(/deletes everything/i)
    expect(REMOVE_FROM_PHONE.body).toMatch(/enrolment code/i)
    // It must NOT claim the account itself goes.
    expect(REMOVE_FROM_PHONE.body).toMatch(/account is untouched/i)
  })

  it('never promises a deletion it cannot make', () => {
    const everything = [TITLE, ...WHOSE_ACCOUNT, ...HOW, REMOVE_FROM_PHONE.title, REMOVE_FROM_PHONE.body, LEGAL_LEAD].join(' ')
    expect(everything).not.toMatch(/your account (is|will be) (deleted|erased|removed)/i)
  })

  it('points at the privacy policy, which needs no sign-in', () => {
    expect(LEGAL_LEAD).toMatch(/privacy policy/i)
  })
})
