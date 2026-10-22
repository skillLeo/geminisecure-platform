/**
 * The Guard App's policy and terms, drawn (M24 §5.2): every heading and paragraph on the screen, the Draft note while
 * the text is a draft, and links that open the app's own screens rather than a web page.
 */

import { act, fireEvent, render } from '@testing-library/react-native'

jest.mock('@gs/core', () => ({
  ...require('../../ring/__tests__/kitMock'),
  SUPPORT_CONTACT: { company: 'Gemini Security', email: 'gemisecja@gmail.com', addressLines: ['Shop #20P', 'Portmore Town Centre', 'Portmore, Saint Catherine', 'Jamaica, West Indies'] },
  supportAddress: () => 'Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies',
}))
jest.mock('expo-router', () => ({ router: { push: jest.fn() } }))

import { router } from 'expo-router'
import { DRAFT_NOTE, GUARD_DOCUMENTS } from '../guardLegal'
import { GuardLegalLinks } from '../GuardLegalLinks'
import GuardDocumentScreen from '../screens/GuardDocumentScreen'

describe('the Guard App document screen', () => {
  it.each(['privacy', 'terms'] as const)('draws every heading and paragraph of the %s text, with the Draft note', async (which) => {
    const screen = await render(<GuardDocumentScreen which={which} />)
    const doc = GUARD_DOCUMENTS[which]

    expect(screen.getByText(DRAFT_NOTE)).toBeTruthy()
    for (const line of doc.intro) expect(screen.getByText(line)).toBeTruthy()
    for (const section of doc.sections) {
      expect(screen.getByText(section.heading)).toBeTruthy()
      for (const p of section.paragraphs) expect(screen.getAllByText(p).length).toBeGreaterThan(0)
    }
  })

  it('opens the app\'s own screens from its links, never a browser', async () => {
    const links = await render(<GuardLegalLinks />)

    await act(async () => { fireEvent.press(links.getByText('Privacy Policy')) })
    await act(async () => { fireEvent.press(links.getByText('Terms of Use')) })

    expect(router.push).toHaveBeenNthCalledWith(1, '/privacy-policy')
    expect(router.push).toHaveBeenNthCalledWith(2, '/terms')
  })
})
