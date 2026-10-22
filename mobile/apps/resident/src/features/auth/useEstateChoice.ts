/**
 * "YOUR ESTATE" — the dropdown in the board's Password place on Log in and its invite-code place on Create account
 * (M47 §1–§2). A list of the active estates (`estates.index`, asked before there is any session), chosen in a sheet;
 * nothing is typed — the community-code field, its hint and "Choose your estate from the list instead" are gone
 * (M47 §1.2 1a–1c, §2.2 2b).
 *
 *   CHOSEN WITHOUT ASKING   this phone's last estate if it is listed, else the only home estate there is. The
 *                           demonstration community is never chosen for anybody: it is on the list, labelled.
 *   SHOWN AT ONCE (M11 A4)  the list this phone last saw is drawn on the first frame and replaced by the server's when
 *                           it arrives; a phone that never had it says it is finding it.
 *   NO LIST                 offline, or the server failed: the box shows the estate this phone last used, and a tap
 *                           asks again.
 *   AN UNLISTED ESTATE      an invite's link (gs://log-in?estate=…) may name an estate the public list does not show:
 *                           it is kept as it came, by its id, so that resident can still sign in.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { useOfflineCache } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import type { Choice } from '@/features/visitors/components/ChooserSheet'
import { chosen, estateLabel, estateOptions, initialEstate, type EstateOption } from './estates'

export type EstateChoice = {
  /** What the box shows: the chosen estate's name, or null with nothing chosen. */
  name: string | null
  /** What the box says with nothing chosen. */
  placeholder: string
  /** The sheet of estates. */
  open: boolean
  choices: Choice[]
  press: () => void
  close: () => void
}

export const FINDING = 'Finding your estate…'
export const CHOOSE = 'Choose your estate'
export const TAP_TO_LOAD = 'Tap to load the estates'

/** The sheet's line for an estate: the parish tells two of one name apart; the demonstration says what it is. */
export const choiceLabel = (estate: EstateOption): string => (estate.demo ? `${estate.name}: demonstration, nobody lives here` : estateLabel(estate))

export function useEstateChoice({ value, onChange, remembered }: { value: string; onChange: (estate: string) => void; remembered: { id: string; name: string } | null }): EstateChoice {
  const list = residentApi.useEstatesIndexQuery(undefined)
  const known = useOfflineCache('estates.index', list.data)
  const options = useMemo(() => estateOptions(known?.items), [known])
  const [open, setOpen] = useState(false)

  // Once a list is to hand, choose what can be chosen without asking.
  useEffect(() => {
    if (options.length === 0 || value.trim().length > 0) return
    const first = initialEstate(options, remembered?.id)
    if (first) onChange(first)
  }, [options, value, remembered, onChange])

  const id = value.trim().toLowerCase()
  const current = chosen(options, id)
  const name = current?.name ?? (id === '' ? null : remembered?.id === id ? remembered.name : id)

  const choices = useMemo<Choice[]>(
    () =>
      options.map((estate) => ({
        key: estate.id,
        label: choiceLabel(estate),
        on: estate.id === current?.id,
        pick: () => {
          onChange(estate.id)
          setOpen(false)
        },
      })),
    [options, current, onChange],
  )

  const press = useCallback(() => {
    if (options.length > 0) return setOpen(true)
    void list.refetch()
  }, [options.length, list])

  return {
    name,
    placeholder: options.length > 0 ? CHOOSE : list.isFetching || list.isLoading ? FINDING : TAP_TO_LOAD,
    open,
    choices,
    press,
    close: () => setOpen(false),
  }
}
