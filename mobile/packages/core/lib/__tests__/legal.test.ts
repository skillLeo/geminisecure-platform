/**
 * The public pages' addresses are DERIVED from the one configured server address (gate
 * one-api-url): nothing else to configure, nothing to fall out of step when the server moves.
 */

const mockOpen = jest.fn()
jest.mock('react-native', () => ({ Linking: { openURL: (url: string) => mockOpen(url) }, Platform: { OS: 'android' } }))
jest.mock('expo-constants', () => ({ __esModule: true, default: { executionEnvironment: 'standalone' }, ExecutionEnvironment: { StoreClient: 'storeClient' } }))

import { LEGAL_PAGES, SUPPORT_CONTACT, legalAddress, legalUrl, openLegal, openSupportEmail, supportAddress, webOrigin } from '../legal'

describe('the public pages both stores require', () => {
  beforeEach(() => mockOpen.mockReset())

  it('live beside the API, on the same server: whatever that server is', () => {
    process.env.EXPO_PUBLIC_API_URL = 'https://app.example.com/api/v1'
    expect(webOrigin()).toBe('https://app.example.com')
    expect(legalUrl('privacy')).toBe('https://app.example.com/legal/privacy')
    expect(legalUrl('terms')).toBe('https://app.example.com/legal/terms')
    expect(legalUrl('deleteAccount')).toBe('https://app.example.com/account/delete')

    process.env.EXPO_PUBLIC_API_URL = 'http://192.168.18.119:8000/api/v1/'
    expect(legalUrl('privacy')).toBe('http://192.168.18.119:8000/legal/privacy')
  })

  it('can be said in words when a link cannot be opened', () => {
    process.env.EXPO_PUBLIC_API_URL = 'https://app.example.com/api/v1'
    expect(legalAddress('deleteAccount')).toBe('app.example.com/account/delete')
  })

  it('opens in the phone browser, and says so honestly when the phone will not', async () => {
    process.env.EXPO_PUBLIC_API_URL = 'https://app.example.com/api/v1'
    mockOpen.mockResolvedValueOnce(undefined)
    await expect(openLegal('terms')).resolves.toBe(true)
    expect(mockOpen).toHaveBeenCalledWith('https://app.example.com/legal/terms')

    mockOpen.mockRejectedValueOnce(new Error('no browser'))
    await expect(openLegal('privacy')).resolves.toBe(false)
  })

  it('names the three pages the Resident App\'s listing asks for, and none that describes the product (M24)', () => {
    expect(Object.keys(LEGAL_PAGES).sort()).toEqual(['deleteAccount', 'privacy', 'terms'])
    expect(Object.values(LEGAL_PAGES)).not.toContain('/support')
    expect(Object.values(LEGAL_PAGES)).not.toContain('/apps')
  })
})

/*
 * The company's own contact details (Q-M33, ruled 2026-09-21). Unlike the page addresses these are
 * NOT derived from the server address — they are compiled in, because Help has to read with no
 * signal. That makes drift the risk, so this asserts the values themselves; `gate:contact` asserts
 * that the store listings, the two apps and the server all carry the same ones.
 */
describe("the company's contact details", () => {
  beforeEach(() => mockOpen.mockReset())

  it('are the real ones, and not a placeholder', () => {
    // The client's own address (M33 §3). The old mailbox on geminisl.com takes no mail.
    expect(SUPPORT_CONTACT.email).toBe('gemisecja@gmail.com')
    expect(SUPPORT_CONTACT.email).not.toMatch(/geminisl\.com/i)
    expect(SUPPORT_CONTACT.company).toBe('Gemini Security')
    expect(supportAddress()).toBe('Shop #20P, Portmore Town Centre, Portmore, Saint Catherine, Jamaica, West Indies')
    for (const value of [SUPPORT_CONTACT.email, SUPPORT_CONTACT.company, supportAddress()]) {
      expect(value).not.toMatch(/example\.(com|org|net)|\{\{|\bTBD\b|placeholder|your company/i)
      expect(value.trim()).not.toBe('')
    }
  })

  it('carry no telephone number, because the company gave none', () => {
    expect(Object.keys(SUPPORT_CONTACT).sort()).toEqual(['addressLines', 'company', 'email'])
    expect(JSON.stringify(SUPPORT_CONTACT)).not.toMatch(/\+?\d[\d\s()-]{6,}/)
  })

  it('open the phone mail app, and say so honestly when the phone has none', async () => {
    mockOpen.mockResolvedValueOnce(undefined)
    await expect(openSupportEmail()).resolves.toBe(true)
    expect(mockOpen).toHaveBeenCalledWith('mailto:gemisecja@gmail.com')

    mockOpen.mockResolvedValueOnce(undefined)
    await expect(openSupportEmail('GeminiSecure Guard')).resolves.toBe(true)
    expect(mockOpen).toHaveBeenLastCalledWith('mailto:gemisecja@gmail.com?subject=GeminiSecure%20Guard')

    mockOpen.mockRejectedValueOnce(new Error('no mail app'))
    await expect(openSupportEmail()).resolves.toBe(false)
  })
})
