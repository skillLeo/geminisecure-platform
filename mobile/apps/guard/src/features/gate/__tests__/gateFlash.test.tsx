/**
 * AFTER AN ADMISSION, HOME SAYS WHAT WAS RECORDED (the client, 27 Sep — U2). An admission or an override takes the officer
 * Home and Home says, once, what the gate log now holds; a message about a moment goes after FLASH_MS or when tapped.
 * And the VerdictSheet really does go Home on an admission, and stays on the scanner for a denial.
 */

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { FLASH_MS, clearGateFlash, flashFor, showGateFlash, useGateFlash } from '../gateFlash'

afterEach(() => {
  clearGateFlash()
  jest.useRealTimers()
})

describe('the words', () => {
  it('names the entry and where it went', () => {
    expect(flashFor({ kind: 'admitted', subject: 'Kemar Brown', pending: false })).toMatchObject({ title: 'Entry recorded: Kemar Brown', detail: 'Saved to the gate log.' })
    expect(flashFor({ kind: 'overridden', subject: 'Kemar Brown', pending: false }).title).toBe('Override recorded: Kemar Brown')
  })

  it('never says the gate log has it when it is still on the phone', () => {
    expect(flashFor({ kind: 'admitted', subject: 'Kemar Brown', pending: true }).detail).toBe('Saved on this phone: it is sent to the gate log by itself when signal returns.')
  })
})

describe('the flash on Home', () => {
  it('is shown once, and goes by itself after FLASH_MS', async () => {
    jest.useFakeTimers()
    const { result } = await renderHook(() => useGateFlash())
    expect(result.current).toBeNull()
    await act(async () => showGateFlash({ kind: 'admitted', subject: 'Kemar Brown', pending: false }))
    expect(result.current?.title).toBe('Entry recorded: Kemar Brown')
    await act(async () => {
      jest.advanceTimersByTime(FLASH_MS - 1)
    })
    expect(result.current).not.toBeNull()
    await act(async () => {
      jest.advanceTimersByTime(1)
    })
    expect(result.current).toBeNull()
  })

  it('a second admission is not cut short by the first one\'s timer', async () => {
    jest.useFakeTimers()
    const { result } = await renderHook(() => useGateFlash())
    await act(async () => showGateFlash({ kind: 'admitted', subject: 'First', pending: false }))
    await act(async () => {
      jest.advanceTimersByTime(FLASH_MS - 1000)
    })
    await act(async () => showGateFlash({ kind: 'admitted', subject: 'Second', pending: false }))
    await act(async () => {
      jest.advanceTimersByTime(1000)
    })
    expect(result.current?.title).toBe('Entry recorded: Second')
  })

  it('goes at once when tapped away', async () => {
    const { result } = await renderHook(() => useGateFlash())
    await act(async () => showGateFlash({ kind: 'admitted', subject: 'Kemar Brown', pending: false }))
    await act(async () => clearGateFlash())
    expect(result.current).toBeNull()
  })
})

describe('the verdict sheet goes Home on an admission, and stays for a denial', () => {
  const sheet = readFileSync(join(__dirname, '..', 'components', 'VerdictSheet.tsx'), 'utf8')

  it('flashes and goes Home for an admission or an override, and only for those', () => {
    // Everything that is not a denial (admitted, overridden) flashes, clears the sheet and goes Home…
    expect(sheet).toMatch(/if \(!isFidelity && outcome\.kind !== 'denied'\) \{\s*showGateFlash\(outcome\)\s*reset\(\)[\s\S]*?router\.replace\('\/\(tabs\)' as never\)\s*return/)
    // …and a denial stays, cleared for the next person after its own pause.
    expect(sheet).toMatch(/const timer = setTimeout\(reset, OUTCOME_MS\)/)
  })
})
