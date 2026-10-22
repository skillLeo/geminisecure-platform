/**
 * The card checkout in demonstration mode (M12 §2.6): test cards only, never a real card, and the card never leaves
 * the phone — only which test card it was.
 */
import {
  DECLINE_SENTENCE, DEMONSTRATION_PAID, EMPTY_CARD, NOT_A_TEST_CARD, TEST_CARDS, TEST_MODE_NOTE, brandOf, cardErrors, formatCardNumber, formatExpiry, isRealCard, last4Of, lastFour, luhn, outcomeOf, testCardFor, testReference,
} from '../testCheckout'

const TODAY = { year: 2026, month: 9 }
const good = { number: '4242 4242 4242 4242', expiry: '12 / 30', cvc: '123', name: 'Andrea Fletcher' }

describe('only Stripe’s test numbers', () => {
  it('knows the published numbers, and every one passes the Luhn check', () => {
    expect(TEST_CARDS.map((c) => c.number)).toContain('4242424242424242')
    for (const card of TEST_CARDS) {
      expect(luhn(card.number)).toBe(true)
      expect(brandOf(card.number)).toBe(card.brand)
    }
  })

  it('refuses a real card, a complete, valid number that is not a test one, and says why', () => {
    expect(isRealCard('4111 1111 1111 1111')).toBe(true) // valid Luhn, not on Stripe's list
    expect(isRealCard('4242 4242 4242 4242')).toBe(false)
    expect(isRealCard('4242 4242 4242 424')).toBe(false) // not complete yet
    expect(isRealCard('4242 4242 4242 4241')).toBe(false) // a typo, not a card
    expect(cardErrors({ ...good, number: '4111 1111 1111 1111' }, TODAY).number).toBe(NOT_A_TEST_CARD)
    expect(NOT_A_TEST_CARD).toMatch(/Never enter a real card/)
  })

  it('answers each test number the way Stripe does, in Stripe’s words', () => {
    expect(outcomeOf('4242424242424242')).toBe('succeeded')
    expect(outcomeOf('4000 0000 0000 0002')).toBe('declined')
    expect(outcomeOf('4000000000009995')).toBe('insufficient_funds')
    expect(DECLINE_SENTENCE.declined).toBe('Your card was declined.')
    expect(testCardFor('5555 5555 5555 4444')?.brand).toBe('mastercard')
  })
})

describe('the form, as a card form behaves', () => {
  it('groups the number as it is printed, and stops at a whole number', () => {
    expect(formatCardNumber('4242424242424242999')).toBe('4242 4242 4242 4242')
    expect(formatCardNumber('42424')).toBe('4242 4')
    expect(formatCardNumber('378282246310005')).toBe('3782 822463 10005')
  })

  it('writes the expiry as MM / YY, and a first digit above 1 is a month', () => {
    expect(formatExpiry('1')).toBe('1')
    expect(formatExpiry('12')).toBe('12 / ')
    expect(formatExpiry('1230')).toBe('12 / 30')
    expect(formatExpiry('4')).toBe('04 / ')
    // Backspace over " / " takes the month's last digit rather than bringing the slash straight back.
    expect(formatExpiry('12 /', '12 / ')).toBe('1')
  })

  it('says what is wrong with each field, in Stripe’s words', () => {
    expect(cardErrors(good, TODAY)).toEqual({})
    const e = cardErrors(EMPTY_CARD, TODAY)
    expect(Object.keys(e).sort()).toEqual(['cvc', 'expiry', 'name', 'number'])
    expect(cardErrors({ ...good, number: '4242 4242' }, TODAY).number).toBe('Your card number is incomplete.')
    expect(cardErrors({ ...good, number: '4242 4242 4242 4241' }, TODAY).number).toBe('Your card number is invalid.')
    expect(cardErrors({ ...good, expiry: '08 / 26' }, TODAY).expiry).toBe("Your card's expiration date is in the past.")
    expect(cardErrors({ ...good, expiry: '09 / 26' }, TODAY).expiry).toBeUndefined() // good to the end of its month
    expect(cardErrors({ ...good, expiry: '12 / 25' }, TODAY).expiry).toBe("Your card's expiration year is in the past.")
    expect(cardErrors({ ...good, expiry: '13 / 30' }, TODAY).expiry).toBe("Your card's expiration month is invalid.")
    expect(cardErrors({ ...good, cvc: '12' }, TODAY).cvc).toBe("Your card's security code is incomplete.")
    // American Express has a four-digit code.
    expect(cardErrors({ ...good, number: '378282246310005', cvc: '123' }, TODAY).cvc).toBe("Your card's security code is incomplete.")
    expect(cardErrors({ ...good, number: '378282246310005', cvc: '1234' }, TODAY).cvc).toBeUndefined()
  })
})

describe('the result is a demonstration: real in the books, no money moved', () => {
  it('says DEMONSTRATION everywhere it could be mistaken, and that no money moved', () => {
    expect(TEST_MODE_NOTE).toMatch(/^Demonstration payment/)
    expect(TEST_MODE_NOTE).toMatch(/no real money moves/)
    expect(TEST_MODE_NOTE).toMatch(/never leaves this phone/)
    expect(DEMONSTRATION_PAID).toMatch(/No money moved/)
    expect(DEMONSTRATION_PAID).toMatch(/real receipt/)
    expect(testReference(123456)).toMatch(/^TEST-[A-Z2-9]{6}$/)
    expect(lastFour('4242 4242 4242 4242')).toBe('•••• 4242')
    expect(last4Of('4242 4242 4242 4242')).toBe('4242')
  })

  it('names every test card by brand and last four uniquely, which is all the server is told', () => {
    const keys = TEST_CARDS.map((card) => `${card.brand}:${card.number.slice(-4)}`)
    expect(new Set(keys).size).toBe(TEST_CARDS.length)
  })

  it('the card never leaves the phone: the screen keeps nothing and sends only { brand, last4 } through one hook', () => {
    const { readFileSync } = jest.requireActual<typeof import('node:fs')>('node:fs')
    const { join } = jest.requireActual<typeof import('node:path')>('node:path')
    for (const file of [join(__dirname, '..', 'testCheckout.ts'), join(__dirname, '..', 'screens', 'TestPayScreen.tsx')]) {
      const code = readFileSync(file, 'utf8')
      expect(code).not.toMatch(/residentApi|fetch\(|submit\(|getStorage|jsonStore|AsyncStorage|SecureStore|console\.log/)
    }
    const screen = readFileSync(join(__dirname, '..', 'screens', 'TestPayScreen.tsx'), 'utf8')
    expect(screen).toMatch(/checkout\.pay\(\{ brand, last4: last4Of\(form\.number\) \}\)/)
    const hook = readFileSync(join(__dirname, '..', 'useCardCheckout.ts'), 'utf8')
    // What the hook sends is the brand and the last four, and never a card number, an expiry or a code.
    expect(hook).toMatch(/brand: card\.brand, last4: card\.last4/)
    expect(hook).not.toMatch(/number|expiry|cvc/i)
  })
})
