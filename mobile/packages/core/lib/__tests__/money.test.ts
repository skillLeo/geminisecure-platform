/**
 * Money is displayed, never computed: the formatter moves characters and must agree with
 * the server's string for every shape the ledger can send.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { money, moneySign } from '../money'

describe('money', () => {
  it('draws the boards’ two forms', () => {
    expect(money('12400.00')).toBe('$12,400.00')
    expect(money('12400.00', 'compact')).toBe('$12,400')
    expect(money('6200', 'compact')).toBe('$6,200')
    expect(money('6200')).toBe('$6,200.00')
    expect(money('0.00')).toBe('$0.00')
    expect(money('0', 'compact')).toBe('$0')
  })

  it('keeps cents exactly as sent: compact hides them only when they are zero', () => {
    expect(money('1234567.5')).toBe('$1,234,567.50')
    expect(money('999.99', 'compact')).toBe('$999.99')
    expect(money('0.10', 'compact')).toBe('$0.10')
    expect(money('38450.005')).toBe('$38,450.005') // never rounded: rounding is arithmetic
  })

  it('shows a credit as negative, and never a negative zero', () => {
    expect(money('-350.5')).toBe('-$350.50')
    expect(money('-0.00')).toBe('$0.00')
    expect(moneySign('-350.5')).toBe(-1)
    expect(moneySign('0.00')).toBe(0)
    expect(moneySign('-0')).toBe(0)
    expect(moneySign('0.01')).toBe(1)
  })

  it('is exact where a float is not', () => {
    // 0.1 + 0.2 !== 0.3 in floating point; a string that says 0.30 must read 0.30.
    expect(money('0.30')).toBe('$0.30')
    expect(money('9007199254740993.00')).toBe('$9,007,199,254,740,993.00') // beyond Number.MAX_SAFE_INTEGER
    expect(money('000123.40')).toBe('$123.40')
  })

  it('never invents a figure from something that is not a decimal string', () => {
    expect(money(null)).toBe('—')
    expect(money(undefined)).toBe('—')
    expect(money('')).toBe('—')
    expect(money('N/A')).toBe('N/A')
    expect(moneySign('N/A')).toBe(0)
  })

  it('does no arithmetic: the source never turns an amount into a number', () => {
    const source = readFileSync(path.join(__dirname, '..', 'money.ts'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
    expect(source).not.toMatch(/parseFloat|parseInt|Number\(|Math\.|toFixed|\+\s*amount|amount\s*[*/+-]/)
  })
})
