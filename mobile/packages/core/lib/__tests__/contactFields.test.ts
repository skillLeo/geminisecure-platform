/**
 * A PHONE NUMBER AND AN EMAIL ADDRESS, READ THE WAY THE SERVER READS THEM (M18C §6, M18G §4).
 *
 * The phone's copy of `JamaicanNumber::normalise` and `EmailAddress::suggest` (tests/Unit on the server hold the same
 * cases): one written form for every Jamaican number however it was typed, another country's kept with its +, and a
 * "Did you mean…?" for the slip that sent a sign-in code to gmail.comm (M18G, 27 Sep).
 */

import { estateCountry, normalisePhone, phoneProblem, phoneWords, suggestEmail } from '../contactFields'

// The fixture estates are Jamaican (jest.setup.js names the build's zone): the words a Jamaican estate's forms say.
const JAMAICA = 'America/Jamaica'
const PHONE_EXAMPLE = 'That does not look like a phone number. Example: 876 555 0123.'
const PHONE_ABROAD = 'That does not look like a Jamaican number. For a number in another country, start with + and the country code: for example +1 876 555 0123.'

describe('one written form for a Jamaican number', () => {
  it.each([
    ['876-555-0123'],
    ['876 555 0123'],
    ['(876) 555-0123'],
    ['8765550123'],
    ['1 876 555 0123'],
    ['1-876-555-0123'],
    ['+1 (876) 555-0123'],
    ['+18765550123'],
    ['001 876 555 0123'],
  ])('%s → +1 876 555 0123', (typed) => {
    expect(normalisePhone(typed)).toBe('+1 876 555 0123')
  })

  it('refuses a seven-digit local number rather than guess the area code', () => {
    expect(normalisePhone('555 0123')).toBeNull()
    expect(phoneProblem('555 0123', JAMAICA)).toBe(PHONE_EXAMPLE)
  })

  it('refuses what is not a number at all, and an area or exchange code no NANP number has', () => {
    for (const typed of ['call me', '876 055 0123', '076 555 0123', '12345']) expect(normalisePhone(typed)).toBeNull()
  })

  it('says nothing about a blank: "required" is the form\'s own sentence', () => {
    expect(normalisePhone('')).toBeNull()
    expect(phoneProblem('   ')).toBeNull()
  })
})

describe('a number from another country', () => {
  it('is kept with its + and its digits', () => {
    // The client's own test number, written the international way.
    expect(normalisePhone('+92 301 111 6259')).toBe('+923011116259')
    expect(normalisePhone('0044 20 7946 0958')).toBe('+442079460958')
    expect(phoneProblem('+92 301 111 6259')).toBeNull()
  })

  it('typed the local way, it is told to start with +, never silently taken as Jamaican', () => {
    // 03011116259: the Pakistani mobile in the client's screenshot, as a Pakistani types it.
    expect(normalisePhone('03011116259')).toBeNull()
    expect(phoneProblem('03011116259', JAMAICA)).toBe(PHONE_ABROAD)
  })
})

describe('the example is the estate\'s own country\'s (M32 F7), never the phone\'s', () => {
  it('on a Jamaican estate, the sentences are the server\'s for Phoenix Park: with no Pakistani number anywhere', () => {
    const words = phoneWords(JAMAICA)

    expect(words).toEqual({ local: '876 555 0123', international: '+1 876 555 0123', example: 'Example: 876 555 0123.', invalid: PHONE_EXAMPLE, abroad: PHONE_ABROAD })
    expect(phoneProblem('555 0123', JAMAICA)).toBe(PHONE_EXAMPLE)
    expect(JSON.stringify(words)).not.toContain('+92')
  })

  it('follows the estate, whatever the phone or its holder: the build\'s zone here is the fixture estate\'s', () => {
    expect(phoneWords()).toEqual(phoneWords(JAMAICA))
    expect(estateCountry(JAMAICA)).toBe('JM')
  })

  it('on a British estate, the British example written with + (as the server reads it there)', () => {
    expect(estateCountry('Europe/London')).toBe('GB')
    expect(phoneWords('Europe/London')).toMatchObject({
      local: '+44 7700 900123',
      example: 'Example: +44 7700 900123.',
      invalid: 'That does not look like a phone number. Write it with + and the country code: for example +44 7700 900123.',
    })
    expect(phoneProblem('12345', 'Europe/London')).toBe('That does not look like a phone number. Write it with + and the country code: for example +44 7700 900123.')
  })

  it('on an estate whose country the table does not hold, says how to write a number and invents no example', () => {
    expect(phoneWords('Asia/Tokyo').invalid).toBe('That does not look like a phone number. Write it with + and the country code first.')
    expect(phoneWords('Asia/Karachi').invalid).not.toMatch(/\d/)
  })

  it('reads a zone that names no country as the platform\'s home, as the server does', () => {
    expect(estateCountry('UTC')).toBe('JM')
    expect(phoneWords('UTC')).toEqual(phoneWords(JAMAICA))
  })
})

describe('"Did you mean…?"', () => {
  it('catches the slip that sent a sign-in code nowhere (M18G)', () => {
    expect(suggestEmail('andrea.fletcher564@gmail.comm')).toBe('andrea.fletcher564@gmail.com')
  })

  it.each([
    ['a@gmial.com', 'a@gmail.com'],
    ['a@gmail.con', 'a@gmail.com'],
    ['a@hotmial.com', 'a@hotmail.com'],
    ['a@yahooo.com', 'a@yahoo.com'],
    ['a@outlok.com', 'a@outlook.com'],
    ['a@iclod.com', 'a@icloud.com'],
    ['a@gmal.com', 'a@gmail.com'], // one letter missing: found by distance, not by the list
    ['A.Fletcher@GMAIL.COMM', 'A.Fletcher@gmail.com'],
  ])('%s → %s', (typed, meant) => {
    expect(suggestEmail(typed)).toBe(meant)
  })

  it('suggests nothing for a right address, an estate\'s own domain, or something that is not an address', () => {
    for (const typed of ['a@gmail.com', 'office@phoenixpark.jm', 'a@company.com.jm', 'not an address', '@gmail.comm', '']) expect(suggestEmail(typed)).toBeNull()
  })
})
