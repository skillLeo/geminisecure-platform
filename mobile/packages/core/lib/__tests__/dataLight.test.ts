import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { dataLight, photoLimits, pollEvery, refetchOnFocus } from '../dataLight'

describe('data-light mode', () => {
  afterEach(() => dataLight.set(false))

  it('is off until someone turns it on, and remembers', () => {
    dataLight.reset()
    expect(dataLight.isOn()).toBe(false)
    dataLight.set(true)
    dataLight.reset() // forget the in-memory copy: what is left is what was stored
    expect(dataLight.isOn()).toBe(true)
  })

  it('tells whoever is listening', () => {
    const heard = jest.fn()
    const stop = dataLight.subscribe(heard)
    dataLight.set(true)
    stop()
    dataLight.set(false)
    expect(heard).toHaveBeenCalledTimes(1)
  })

  it('stretches a convenience refresh, shrinks a photo, and stops re-asking on focus', () => {
    expect(pollEvery(30_000, false)).toBe(30_000)
    expect(pollEvery(30_000, true)).toBe(120_000)
    expect(photoLimits(false)).toEqual({ maxSide: 1600, quality: 0.6 })
    expect(photoLimits(true)).toEqual({ maxSide: 1024, quality: 0.45 })
    expect(refetchOnFocus(true)).toBe(false)
  })
})

describe('safety does not get a cheaper tier', () => {
  const ROOT = path.resolve(__dirname, '..', '..', '..', '..')
  const walk = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
    if (['node_modules', '__tests__', 'lifted'].includes(name)) return []
    const full = path.join(dir, name)
    return statSync(full).isDirectory() ? walk(full) : /\.tsx?$/.test(name) ? [full] : []
  })

  it('nothing in an alert, alertness, gate, approval or offline-queue path asks about data-light', () => {
    const safety = [
      'packages/core/safety', 'packages/core/offline',
      'apps/guard/src/features/duress', 'apps/guard/src/features/alertness', 'apps/guard/src/features/gate', 'apps/guard/src/features/walkup', 'apps/guard/src/offline',
      'apps/resident/src/features/panic', 'apps/resident/src/offline',
    ]
    for (const folder of safety) {
      for (const file of walk(path.join(ROOT, folder))) {
        expect({ file: path.relative(ROOT, file), usesDataLight: /dataLight|pollEvery|useDataLight/.test(readFileSync(file, 'utf8')) }).toEqual({ file: path.relative(ROOT, file), usesDataLight: false })
      }
    }
  })
})
