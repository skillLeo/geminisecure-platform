import { renderHook } from '@testing-library/react-native'
import { getStorage, jsonStore } from '../storage'
import { useOfflineCache } from '../useOfflineCache'

const save = <T,>(key: string, value: T) => jsonStore<T>(getStorage(), `cache:${key}`, 'v1').set(value)

describe('useOfflineCache', () => {
  it('hands back what was saved when there is no answer, and the answer when there is one', async () => {
    save('hours:w1', { week: 1 })
    const { result, rerender } = await renderHook(({ data }: { data: { week: number } | undefined }) => useOfflineCache('hours:w1', data), { initialProps: { data: undefined as { week: number } | undefined } })
    expect(result.current).toEqual({ week: 1 })
    await rerender({ data: { week: 11 } })
    expect(result.current).toEqual({ week: 11 })
  })

  // My Hours steps from week to week on ONE mounted screen. With no signal it used to draw the FIRST week's saved
  // hours under every other week's dates, because the saved copy was read once, at mount.
  it('a changed key never gets the old key\'s saved copy, not even for one render', async () => {
    save('hours:w1', { week: 1 })
    save('hours:w2', { week: 2 })
    const seen: [string, number | undefined][] = []
    const { result, rerender } = await renderHook(({ k }: { k: string }) => { const v = useOfflineCache<{ week: number }>(k, undefined); seen.push([k, v?.week]); return v }, { initialProps: { k: 'hours:w1' } })
    await rerender({ k: 'hours:w2' })
    expect(result.current).toEqual({ week: 2 })
    await rerender({ k: 'hours:w3' })
    expect(result.current).toBeUndefined() // nothing was ever saved for that week: say nothing, not last week's
    // Every single render, including the first one after each key change, drew that key's own copy or nothing.
    for (const [k, week] of seen) expect(week).toBe(k === 'hours:w1' ? 1 : k === 'hours:w2' ? 2 : undefined)
  })

  it('a screen that stays mounted falls back to the NEWEST thing it saved, not to what was on disk when it mounted', async () => {
    const { result, rerender } = await renderHook(({ data }: { data: { n: number } | undefined }) => useOfflineCache('long.lived', data), { initialProps: { data: undefined as { n: number } | undefined } })
    expect(result.current).toBeUndefined()
    await rerender({ data: { n: 1 } })
    await rerender({ data: { n: 2 } })
    await rerender({ data: undefined }) // the API cache was emptied, or the request failed with nothing kept
    expect(result.current).toEqual({ n: 2 })
  })
})
