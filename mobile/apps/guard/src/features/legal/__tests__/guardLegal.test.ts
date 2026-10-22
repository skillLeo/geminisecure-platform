/**
 * The Guard App's own privacy policy and terms (M24 §5.2, D-223): on the phone, readable before enrolment, at no
 * web address, and saying what the app really does.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

jest.mock('@gs/core', () => ({
  SUPPORT_CONTACT: { company: 'Gemini Security', email: 'gemisecja@gmail.com', addressLines: ['Shop #20P', 'Portmore Town Centre', 'Portmore, Saint Catherine', 'Jamaica, West Indies'] },
  supportAddress: () => 'Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies',
}))

import { GUARD_DOCUMENTS, GUARD_DOCUMENT_ROUTES, GUARD_PRIVACY, GUARD_TERMS } from '../guardLegal'
import { policyText } from '../../tracking/trackingPolicy'

const words = (doc: typeof GUARD_PRIVACY) => [...doc.intro, ...doc.sections.flatMap((s) => [s.heading, ...s.paragraphs])].join('\n')
const app = (...p: string[]) => path.join(__dirname, '..', '..', '..', '..', ...p)

describe("the Guard App's privacy policy and terms", () => {
  it('live on the phone: no web address, no link to the website', () => {
    for (const doc of Object.values(GUARD_DOCUMENTS)) {
      expect(words(doc)).not.toMatch(/https?:|www\.|\/legal\/|\/support|\/apps|geminisl\.com\//)
    }
  })

  it('open from a route of their own that a phone with no session may visit', () => {
    const gate = readFileSync(app('src', 'features', 'boot', 'SessionGate.tsx'), 'utf8')
    for (const route of Object.values(GUARD_DOCUMENT_ROUTES)) {
      const segment = route.replace(/^\//, '')
      expect(gate).toContain(`'${segment}'`)
      expect(readFileSync(app('app', `${segment}.tsx`), 'utf8')).toContain('GuardDocumentScreen')
    }
  })

  it('say that on duty sharing needs the officer\'s agreement, stops at clock out, and is kept as long as the tracker says', () => {
    const privacy = words(GUARD_PRIVACY)
    expect(privacy).toMatch(/only if you agree/i)
    expect(privacy).toMatch(/even when the app is closed/)
    expect(privacy).toMatch(/until you clock out/)
    expect(privacy).toMatch(/Saying no does not stop you working/)
    // The same number the consent screen shows when the server has not said otherwise.
    const kept = policyText(null).find((s) => s.heading === 'How long it is kept')?.body ?? ''
    const days = /after (\d+) days/.exec(kept)?.[1]
    expect(days).toBeDefined()
    expect(privacy).toContain(`deleted after ${days} days`)
  })

  it('say the identity check stays on the phone and the alertness check-ins need a separate yes', () => {
    const privacy = words(GUARD_PRIVACY)
    expect(privacy).toMatch(/No image, template or score leaves the phone/)
    expect(privacy).toMatch(/supervisor can start your shift with their PIN/)
    expect(privacy).toMatch(/Alertness check-ins[\s\S]*agree to them separately/)
  })

  it('never describe the duress alert as reaching the emergency services', () => {
    const terms = words(GUARD_TERMS)
    expect(terms).toMatch(/does not contact the police, the fire service or an ambulance/)
    expect(terms).toContain('call 119')
    expect(`${words(GUARD_PRIVACY)}\n${terms}`).not.toMatch(/\b(911|first responders|emergency services)\b/i)
  })

  it('give the company\'s real contact details, and no telephone number', () => {
    for (const doc of Object.values(GUARD_DOCUMENTS)) {
      const text = words(doc)
      expect(text).toContain('gemisecja@gmail.com')
      expect(text).toContain('Shop #20P, Portmore Town Centre')
      expect(text).not.toMatch(/\+?1?[\s(]*876[\s)]*\d{3}/)
    }
  })
})
