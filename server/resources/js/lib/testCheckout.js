/**
 * THE TEST CARD CHECKOUT's rules — the same as the Resident App's (apps/resident/src/features/dues/testCheckout.ts).
 *
 * A preview of paying by card, never a payment. Dues are paid by hand (Q-012): this takes ONLY the card numbers
 * Stripe publishes for testing (https://docs.stripe.com/testing), wipes a real one the moment it recognises it,
 * makes no request and keeps nothing. The outcomes are Stripe's own for its test numbers, in its own words.
 */

/** Stripe's published test numbers, and what each one does. Any future expiry, any CVC, any name. */
export const TEST_CARDS = Object.freeze([
    { number: '4242424242424242', brand: 'visa', outcome: 'succeeded', label: 'Visa · succeeds' },
    { number: '4000056655665556', brand: 'visa', outcome: 'succeeded', label: 'Visa (debit) · succeeds' },
    { number: '5555555555554444', brand: 'mastercard', outcome: 'succeeded', label: 'Mastercard · succeeds' },
    { number: '378282246310005', brand: 'amex', outcome: 'succeeded', label: 'American Express · succeeds' },
    { number: '6011111111111117', brand: 'discover', outcome: 'succeeded', label: 'Discover · succeeds' },
    { number: '4000000000000002', brand: 'visa', outcome: 'declined', label: 'Declined' },
    { number: '4000000000009995', brand: 'visa', outcome: 'insufficient_funds', label: 'Insufficient funds' },
    { number: '4000000000000069', brand: 'visa', outcome: 'expired_card', label: 'Expired card' },
    { number: '4000000000000127', brand: 'visa', outcome: 'incorrect_cvc', label: 'Incorrect security code' },
])

export const DECLINE_SENTENCE = Object.freeze({
    declined: 'Your card was declined.',
    insufficient_funds: 'Your card has insufficient funds.',
    expired_card: 'Your card has expired.',
    incorrect_cvc: 'Your card’s security code is incorrect.',
})

// The console's words say PREVIEW, never "test", where they sit near a sum (DR-052; M77 §6). The rules are unchanged.
export const NOT_A_TEST_CARD =
    'That is not one of the numbers this preview takes, so it was cleared. Use one like 4242 4242 4242 4242. Never enter a real card here.'

export const TEST_MODE_NOTE =
    'Preview. No real money moves, and nothing typed here is sent to the server or saved. Use a card number such as 4242 4242 4242 4242, any future date and any security code.'

export const NOTHING_PAID =
    'This was a preview. No money moved, nothing was posted to the ledger, no receipt was allocated, and this household’s balance has not changed. When card payments are switched on, a real payment will look like this, and it will post and receipt itself.'

export const BRAND_NAME = Object.freeze({ visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', discover: 'Discover', unknown: 'Card' })

export const digitsOf = (text) => String(text ?? '').replace(/\D+/g, '')

export function brandOf(number) {
    const d = digitsOf(number)
    if (/^4/.test(d)) return 'visa'
    if (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(d)) return 'mastercard'
    if (/^3[47]/.test(d)) return 'amex'
    if (/^(6011|65|64[4-9])/.test(d)) return 'discover'
    return 'unknown'
}

export const lengthFor = (brand) => (brand === 'amex' ? 15 : 16)
export const cvcLengthFor = (brand) => (brand === 'amex' ? 4 : 3)

/** "4242424242424242" → "4242 4242 4242 4242" · American Express "3782 822463 10005". */
export function formatCardNumber(text) {
    const brand = brandOf(text)
    const d = digitsOf(text).slice(0, lengthFor(brand))
    if (brand === 'amex') return [d.slice(0, 4), d.slice(4, 10), d.slice(10, 15)].filter(Boolean).join(' ')
    return (d.match(/.{1,4}/g) ?? []).join(' ')
}

/** "1230" → "12 / 30"; a lone first digit above 1 is a month; deleting the " / " takes the month's last digit. */
export function formatExpiry(text, previous = '') {
    let d = digitsOf(text).slice(0, 4)
    if (previous.endsWith(' / ') && String(text).length < previous.length) d = d.slice(0, 1)
    if (d.length === 1 && Number(d) > 1) d = `0${d}`
    if (d.length < 2) return d
    return `${d.slice(0, 2)} / ${d.slice(2)}`
}

export function luhn(number) {
    const d = digitsOf(number)
    if (d.length < 12) return false
    let sum = 0
    for (let i = 0; i < d.length; i++) {
        let n = Number(d[d.length - 1 - i])
        if (i % 2 === 1) {
            n *= 2
            if (n > 9) n -= 9
        }
        sum += n
    }
    return sum % 10 === 0
}

export const testCardFor = (number) => TEST_CARDS.find((card) => card.number === digitsOf(number)) ?? null

/** A complete, valid number that is not a test one: most likely a real card, and it is wiped. */
export function isRealCard(number) {
    const d = digitsOf(number)
    return d.length === lengthFor(brandOf(d)) && luhn(d) && testCardFor(d) === null
}

/** The first problem with each field, in Stripe's words. `today` is this month AT THE ESTATE, month 1–12. */
export function cardErrors(form, today) {
    const errors = {}
    const number = digitsOf(form.number)
    const brand = brandOf(number)
    if (number.length === 0) errors.number = 'Enter the card number.'
    else if (number.length < lengthFor(brand)) errors.number = 'Your card number is incomplete.'
    else if (!luhn(number)) errors.number = 'Your card number is invalid.'
    else if (!testCardFor(number)) errors.number = NOT_A_TEST_CARD

    const expiry = digitsOf(form.expiry)
    if (expiry.length === 0) errors.expiry = 'Enter the expiry date.'
    else if (expiry.length < 4) errors.expiry = 'Your card’s expiration date is incomplete.'
    else {
        const month = Number(expiry.slice(0, 2))
        const year = 2000 + Number(expiry.slice(2, 4))
        if (month < 1 || month > 12) errors.expiry = 'Your card’s expiration month is invalid.'
        else if (year < today.year) errors.expiry = 'Your card’s expiration year is in the past.'
        else if (year === today.year && month < today.month) errors.expiry = 'Your card’s expiration date is in the past.'
        else if (year > today.year + 50) errors.expiry = 'Your card’s expiration year is invalid.'
    }

    const cvc = digitsOf(form.cvc)
    if (cvc.length === 0) errors.cvc = 'Enter the security code.'
    else if (cvc.length < cvcLengthFor(brand)) errors.cvc = 'Your card’s security code is incomplete.'

    if (String(form.name ?? '').trim().length < 2) errors.name = 'Enter the name on the card.'
    return errors
}

export const outcomeOf = (number) => testCardFor(number)?.outcome ?? 'declined'

export const lastFour = (number) => `•••• ${digitsOf(number).slice(-4)}`

/** "TEST-7F3K2Q" — never mistaken for an estate receipt number. */
export function testReference(seed) {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    let n = Math.abs(Math.floor(seed)) || 1
    let out = ''
    for (let i = 0; i < 6; i++) {
        out += alphabet[n % alphabet.length]
        n = Math.floor(n / alphabet.length) + (i + 1) * 7919
    }
    return `TEST-${out}`
}
