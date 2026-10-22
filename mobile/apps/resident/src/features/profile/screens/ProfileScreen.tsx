/**
 * resident-app-17 · Profile — rebuilt from the board (M42 §5 #28), every value from the design tokens and parts.
 *
 * A TAB screen: the navigator draws the tab bar. The bar: a spacer, "Profile", the gear (Settings) — nothing else.
 * Then the board's cards, in its order: the hero (initials in a gold-ringed circle with the pencil, the name, the unit,
 * the role); Contact details; Household — each member is the initials, the name and the role chip, and NOTHING else:
 * everything about them (awaiting verification, their invite and its Send / Resend / Cancel, their access, Remove)
 * is in the sheet that opens when you tap them — then "+ Add household member"; Vehicles & pets as pills; Emergency
 * contact, one line each, which opens its edit.
 *
 * WHAT THE BOARD DOES NOT DRAW, AND M50 §5.3–§5.4 ASKED FOR: a vehicle, a pet or a contact opens its own screen to be
 * changed or removed, and "+ Add vehicle", "+ Add pet" and "+ Add emergency contact" sit at the foot of their cards in
 * the "+ Add household member" row's style — live only, for whoever the server lets change them; the board's own picture
 * (fidelity mode) is drawn as the board draws it, with its own pet. Sign out and the rows into Settings live on
 * Settings (board 18), not here.
 *
 * ONE THING THE BOARD DOES NOT DRAW (M47 §6d): "My e-Pass", a gold pill beside the role tag. A Member could not reach
 * their own e-Pass anywhere — Home offers it to Dependents and Helpers alone, and it is not a visitor, so it left the
 * Visitors tab (§5.3). It sits on the role tag's own line, so the hero is no taller and nothing below it moves.
 */

import { useEffect, useRef, useState, type Ref, type RefObject } from 'react'
import { Image, RefreshControl, ScrollView, StyleSheet, View } from 'react-native'
import { router } from 'expo-router'
import { ConfirmSheet, EmptyState, Icon, Pressable, ResidentButton, Text, c, now, siteDayIso, useFidelityReady, type IconName } from '@gs/core'
import { flags } from '@gs/core/lib'
import { AppBar, Card, Chip, ChipRow, PRESSED, SecondaryButton, SectionLabel, Toggle } from '@/design/parts'
import { corner, fill, font, icon, ink, line, size, space, stroke } from '@/design/tokens'
import { DAY_NAMES, DEFAULT_WINDOW, END_PRESETS, dateWords, overnightLine, stepDate, stepTime, timeWords, toggleDay, windowOf, type HelperWindow } from '../helperHours'
import { LEVELS, contactLine, initialsOf, isLevel, isPrimary, isSelf, isVerified, memberTag, petLine, phoneShown, relationshipLabel, removable, vehicleLine, type Contact, type Member, type Pet, type Row, type Vehicle } from '../household'
import { CancelInviteSheet, InviteUnderRow, hasInviteLine } from '../components/InviteParts'
import { hoursSheet, levelSheet, removeSheet } from '../sheets'
import { useInvites } from '../useInvites'
import { useProfile, type ProfileModel } from '../useProfile'

export { signOutWords } from '../signOutWords'

const go = (path: string) => router.push(path as never)

const ROUTES = { details: '/household/details', member: '/household/add', contact: '/household/contact', vehicle: '/household/vehicle', pet: '/household/pet', settings: '/settings', epass: '/visitors/pass/e-pass' } as const

/** The e-Pass pill's touch area, grown to 44 points tall around its 18. */
const EPASS_REACH = { top: space[13], bottom: space[13], left: space[6], right: space[6] }

/** Said in the photo sheet, before anything is chosen. */
const PHOTO_SHEET_LINE = 'It is kept with your account on your estate’s server and shows on every phone you sign in on. Deleting your account deletes it.'

/* ---- the hero ------------------------------------------------------------------------------------- */

function Hero({ m }: { m: ProfileModel }) {
  const photo = m.photo
  const opener = useRef<View>(null)
  // The pencil changes the photo; where this phone cannot, it opens the contact details.
  const edit = photo.canChange ? photo.sheet.ask : () => go(ROUTES.details)
  return (
    <Card pad={13}>
      <View style={s.hero}>
        <View style={s.avatarWrap}>
          <View style={s.avatar}>
            {photo.uri ? <Image source={{ uri: photo.uri }} style={s.avatarImage} accessibilityIgnoresInvertColors resizeMode="cover" /> : <Text {...font.poppins700_20} color={ink.onTint}>{m.hero.initials}</Text>}
          </View>
          {m.denied ? null : (
            <Pressable ref={opener} onPress={edit} role="button" aria-label={photo.canChange ? (photo.hasPhoto ? 'Change your profile photo' : 'Add a profile photo') : 'Edit your contact details'} hitSlop={size[12]} style={s.pencil}>
              <Icon name="p-edit" size={icon[11]} color={ink.onDark} />
            </Pressable>
          )}
        </View>
        <View style={s.heroText}>
          <Text {...font.poppins600_17} color={ink.heading}>{m.hero.name || 'Profile'}</Text>
          {m.hero.place ? <View style={s.place}><Text {...font.inter400_11_5} color={ink.secondary}>{m.hero.place}</Text></View> : null}
          {m.hero.role || !m.denied ? (
            <View style={s.tags}>
              {m.hero.role ? <View style={s.roleTag}><Text {...font.inter700_9_5} color={ink.onTint}>{m.hero.role}</Text></View> : null}
              {m.denied ? null : (
                <Pressable onPress={() => go(ROUTES.epass)} role="button" aria-label="Show my own e-Pass" hitSlop={EPASS_REACH} style={({ pressed }) => [s.epassTag, pressed ? s.pressedGold : null]}>
                  <Icon name="qa-tile-4" size={icon[11]} color={ink.onGoldTint} />
                  <Text {...font.inter700_9_5} color={ink.onGoldTint}>{'My e-Pass'}</Text>
                </Pressable>
              )}
            </View>
          ) : null}
        </View>
      </View>
      <ConfirmSheet
        open={photo.sheet.open}
        title={photo.hasPhoto ? 'Your profile photo' : 'Add a profile photo'}
        lines={[PHOTO_SHEET_LINE]}
        confirmLabel="Choose from your photos"
        destructive={false}
        working={false}
        blocked={photo.blocked}
        onConfirm={photo.sheet.choose}
        onCancel={photo.sheet.cancel}
        returnFocusTo={opener}
      >
        <View style={s.sheetActions}>
          {photo.blocked
            ? <ResidentButton variant="outline" label="Take a photo" onPress={photo.sheet.take} disabled disabledReason={photo.blocked} />
            : <ResidentButton variant="outline" label="Take a photo" onPress={photo.sheet.take} />}
          {photo.hasPhoto && !photo.blocked ? <SecondaryButton label="Remove your photo" onPress={photo.sheet.remove} /> : null}
        </View>
      </ConfirmSheet>
    </Card>
  )
}

/* ---- contact details ------------------------------------------------------------------------------ */

function InfoRow({ glyph, text, label, onPress }: { glyph: IconName; text: string; label: string; onPress?: () => void }) {
  const body = (
    <>
      <Icon name={glyph} size={icon[17]} color={ink.field} />
      <View style={s.infoText}><Text {...font.inter500_12_5} color={ink.heading}>{text}</Text></View>
    </>
  )
  return onPress ? (
    <Pressable onPress={onPress} role="link" aria-label={label} hitSlop={{ top: size[6], bottom: size[6] }} style={s.infoRow}>{body}</Pressable>
  ) : (
    <View style={s.infoRow} accessible aria-label={label}>{body}</View>
  )
}

function Details({ m }: { m: ProfileModel }) {
  const d = m.details
  if (d.status !== 'ready' || !d.linked) return null
  return (
    <>
      <SectionLabel>Contact details</SectionLabel>
      <Card pad={13}>
        {d.email ? <InfoRow glyph="input-5" text={d.email} label={`Email: ${d.email}. Change it`} onPress={() => go(ROUTES.details)} /> : null}
        {d.phone ? <InfoRow glyph="call-btn" text={phoneShown(d.phone)} label={`Phone: ${phoneShown(d.phone)}. Change it`} onPress={() => go(ROUTES.details)} /> : null}
      </Card>
    </>
  )
}

/* ---- household ------------------------------------------------------------------------------------ */

const sameMember = (a: Pick<Member, 'id'> | null | undefined, b: Pick<Member, 'id'>): boolean => !!a && String(a.id) === String(b.id)

function MemberRow({ member, tag, onPress, divider, rowRef }: { member: Member; tag: string; onPress: () => void; divider: boolean; rowRef?: Ref<View> }) {
  return (
    <Pressable ref={rowRef} onPress={onPress} role="button" aria-label={`${member.full_name}, ${tag}. Open`} style={({ pressed }) => [divider ? s.memberRowRule : s.memberRow, pressed ? s.pressed : null]}>
      <View style={s.memberAvatar}><Text {...font.inter700_11} color={ink.onTint}>{initialsOf(member.full_name)}</Text></View>
      {/* One line, as the board's rows are (M48 §2.1): everything else about them is in their sheet. */}
      <View style={s.memberName}><Text {...font.inter600_12_5} color={ink.heading} numberOfLines={1}>{member.full_name}</Text></View>
      <View style={s.memberTag}><Text {...font.inter700_9} color={ink.secondary}>{tag}</Text></View>
    </Pressable>
  )
}

/**
 * Everything about one member that used to sit under their row — opened by tapping them (M42 §5 #28, M48 §2.1): their
 * access and their RELATIONSHIP (the row's chip is the access level alone), a Helper's days and hours ("Comes: …", and
 * "Days and hours" for the primary, 8 Oct 2026), "Awaiting verification", the invite and its Send / Resend / Cancel or
 * "Invites go by email for now — add an email to invite them.", Change access, and Remove — which asks once more in a
 * sheet whose confirm is red.
 */
function MemberSheet({ m, member, invites, onClose, onRemove, onAccess, onHours }: {
  m: ProfileModel
  member: Member | null
  invites: ReturnType<typeof useInvites>
  onClose: () => void
  onRemove: (member: Member) => void
  onAccess: (member: Member) => void
  onHours: (member: Member) => void
}) {
  const primary = m.manage.allowed === true
  const shown = member ? invites.withAnswer(member) : null
  const unverified = !!member && !isPrimary(member) && !isVerified(member)
  const mayRemove = !!member && primary && removable(member, m.manage.selfId, false)
  const mayChangeAccess = mayRemove && m.manage.levelsOpen
  // A Helper's days and hours (8 Oct 2026): the server sends them for a Helper alone; only the primary changes them.
  const hours = member?.access_window ?? null
  const invite = !!shown && !!member && !isSelf(member, m.manage.selfId) && hasInviteLine(shown, invites.now)
  return (
    <ConfirmSheet
      open={!!member}
      title={member?.full_name ?? ''}
      lines={member ? [`${memberTag(member)} · ${relationshipLabel(member.relationship)}`, ...(hours ? [`Comes: ${hours.line}`] : []), ...(unverified ? ['Awaiting verification by the estate office'] : [])] : []}
      confirmLabel="Done"
      destructive={false}
      working={false}
      onConfirm={onClose}
      onCancel={onClose}
    >
      <View style={s.sheetActions}>
        {invite && shown ? <InviteUnderRow member={shown} control={invites} manage={primary} /> : null}
        {mayRemove && hours && member ? <SecondaryButton label="Days and hours" name={`Set ${member.full_name}'s days and hours`} onPress={() => onHours(member)} /> : null}
        {mayChangeAccess && member ? <SecondaryButton label="Change access" name={`Change ${member.full_name}'s access`} onPress={() => onAccess(member)} /> : null}
        {/* Remove asks again in its own sheet, whose confirm is the red one (RemoveMemberSheet, destructive). */}
        {mayRemove && member ? <SecondaryButton label="Remove from household" name={`Remove ${member.full_name} from your household`} onPress={() => onRemove(member)} /> : null}
      </View>
    </ConfirmSheet>
  )
}

function LevelSheet({ m, returnFocusTo, fallbackFocusTo }: { m: ProfileModel; returnFocusTo: RefObject<View | null>; fallbackFocusTo: readonly RefObject<View | null>[] }) {
  const flow = m.manage.levelChange
  const words = flow.target ? levelSheet(flow.target.member, flow.target.level, m.manage.online) : null
  return (
    <ConfirmSheet
      open={flow.open && !!words}
      title={words?.title ?? ''}
      lines={words?.lines ?? []}
      confirmLabel={words?.confirmLabel ?? ''}
      cancelLabel={words?.cancelLabel}
      working={flow.working}
      problem={flow.problem}
      blocked={words?.blocked}
      destructive={words?.destructive ?? false}
      onConfirm={flow.confirm}
      onCancel={flow.cancel}
      returnFocusTo={returnFocusTo}
      fallbackFocusTo={fallbackFocusTo}
    >
      {flow.target ? (
        <View role="radiogroup" aria-label="Access in the app" style={s.levels}>
          {LEVELS.map((level) => {
            const on = level.value === flow.target?.level
            return (
              <Pressable
                key={level.value}
                onPress={() => flow.target && !flow.working && flow.ask({ member: flow.target.member, level: level.value })}
                disabled={flow.working}
                role="radio"
                aria-checked={on}
                aria-label={`${level.label}. ${level.sentence}`}
                style={on ? s.levelOn : s.level}
              >
                <Text {...(on ? font.inter700_13 : font.inter600_13)} color={ink.heading}>{level.label}</Text>
                <View style={s.levelLine}><Text {...font.inter400_11_lh1_45} color={ink.label}>{level.sentence}</Text></View>
              </Pressable>
            )
          })}
        </View>
      ) : null}
    </ConfirmSheet>
  )
}

/** "From  − 7:00 AM +" — one time, moved half an hour at a time, or one day ("Last day"), moved a day at a time. */
function Stepper({ label, value, spoken, unit, onStep, disabled }: { label: string; value: string; spoken: string; unit: string; onStep: (by: number) => void; disabled: boolean }) {
  return (
    <View style={s.stepRow}>
      <View style={s.stepLabel}><Text {...font.inter600_12_5} color={ink.heading}>{label}</Text></View>
      <Pressable onPress={() => onStep(-1)} disabled={disabled} role="button" aria-label={`${label} ${spoken}: ${unit} earlier`} hitSlop={size[6]} style={({ pressed }) => [s.stepButton, pressed ? s.pressedOnTint : null]}>
        <Icon name="qty-btn" size={icon[13]} color={ink.action} />
      </Pressable>
      <View style={s.stepValue}><Text {...font.inter700_13} color={ink.heading} align="center">{value}</Text></View>
      <Pressable onPress={() => onStep(1)} disabled={disabled} role="button" aria-label={`${label} ${spoken}: ${unit} later`} hitSlop={size[6]} style={({ pressed }) => [s.stepButton, pressed ? s.pressedOnTint : null]}>
        <Icon name="qty-btn-2" size={icon[13]} color={ink.action} />
      </Pressable>
    </View>
  )
}

/** A switch with its words beside it, and what it does under them. */
function SwitchRow({ label, line, on, onChange, disabled }: { label: string; line: string; on: boolean; onChange: (next: boolean) => void; disabled: boolean }) {
  return (
    <View style={s.switchRow}>
      <View style={s.switchText}>
        <Text {...font.inter600_12_5} color={ink.heading}>{label}</Text>
        <View style={s.levelLine}><Text {...font.inter400_11_lh1_45} color={ink.label}>{line}</Text></View>
      </View>
      <Toggle on={on} onChange={disabled ? undefined : onChange} label={label} />
    </View>
  )
}

/**
 * A Helper's days and hours (the user, 8 Oct 2026) — the primary resident's: the days as chips, from and until on the
 * half hour (until earlier than from is overnight), an end date or none, and "Pause now". Every change asks again,
 * about the new window (as the level sheet does), so what is confirmed is exactly what is drawn. Online only.
 */
function HoursSheet({ m, returnFocusTo, fallbackFocusTo }: { m: ProfileModel; returnFocusTo: RefObject<View | null>; fallbackFocusTo: readonly RefObject<View | null>[] }) {
  const flow = m.manage.hoursChange
  const target = flow.target
  const today = siteDayIso(now())
  const words = target ? hoursSheet(target.member, target.window, windowOf(target.member), m.manage.online) : null
  const change = (next: (w: HelperWindow) => HelperWindow) => { if (target && !flow.working) flow.ask({ member: target.member, window: next(target.window) }) }
  const w = target?.window
  const overnight = w ? overnightLine(w) : null
  return (
    <ConfirmSheet
      open={flow.open && !!words}
      title={words?.title ?? ''}
      lines={words?.lines ?? []}
      confirmLabel={words?.confirmLabel ?? ''}
      cancelLabel={words?.cancelLabel}
      working={flow.working}
      problem={flow.problem}
      blocked={words?.blocked}
      destructive={words?.destructive ?? false}
      onConfirm={flow.confirm}
      onCancel={flow.cancel}
      returnFocusTo={returnFocusTo}
      fallbackFocusTo={fallbackFocusTo}
    >
      {w ? (
        <View style={s.hours}>
          <Text {...font.inter600_12_5} color={ink.heading}>Days</Text>
          <View role="group" aria-label="The days they may come">
            <ChipRow wrap>
              {DAY_NAMES.map(([day, short, long]) => (
                <Chip key={day} small many label={short} name={long} on={w.days.includes(day)} onPress={() => change((x) => ({ ...x, days: toggleDay(x.days, day) }))} />
              ))}
            </ChipRow>
          </View>
          <Stepper label="From" value={timeWords(w.from)} spoken={timeWords(w.from)} unit="half an hour" disabled={flow.working} onStep={(by) => change((x) => ({ ...x, from: stepTime(x.from, by) }))} />
          <Stepper label="Until" value={timeWords(w.to)} spoken={timeWords(w.to)} unit="half an hour" disabled={flow.working} onStep={(by) => change((x) => ({ ...x, to: stepTime(x.to, by) }))} />
          {overnight ? <Text {...font.inter400_11_lh1_45} color={ink.label}>{overnight}</Text> : null}
          <SwitchRow
            label="Ends on a date"
            line={w.until ? `Their e-Pass stops working after ${dateWords(w.until)}.` : 'No end date: their days and hours carry on until you change them.'}
            on={!!w.until}
            disabled={flow.working}
            onChange={(on) => change((x) => ({ ...x, until: on ? (x.until ?? stepDate(today, 7)) : null }))}
          />
          {w.until ? (
            <>
              <Stepper label="Last day" value={dateWords(w.until)} spoken={dateWords(w.until)} unit="one day" disabled={flow.working} onStep={(by) => change((x) => ({ ...x, until: x.until ? stepDate(x.until, by, today) : x.until }))} />
              <View role="radiogroup" aria-label="Quick end dates">
                <ChipRow wrap>
                  {END_PRESETS.map((preset) => (
                    <Chip key={preset.label} small label={preset.label} name={preset.name} on={w.until === preset.from(today)} onPress={() => change((x) => ({ ...x, until: preset.from(today) }))} />
                  ))}
                </ChipRow>
              </View>
            </>
          ) : null}
          <SwitchRow
            label="Pause now"
            line="Blocks them at once: their e-Pass is cancelled and every gate refuses it until you turn this off."
            on={w.paused}
            disabled={flow.working}
            onChange={(paused) => change((x) => ({ ...x, paused }))}
          />
        </View>
      ) : null}
    </ConfirmSheet>
  )
}

function RemoveMemberSheet({ m, returnFocusTo, fallbackFocusTo }: { m: ProfileModel; returnFocusTo: RefObject<View | null>; fallbackFocusTo: readonly RefObject<View | null>[] }) {
  const flow = m.manage.removal
  const words = flow.target ? removeSheet(flow.target, m.manage.online) : null
  return (
    <ConfirmSheet
      open={flow.open && !!words}
      title={words?.title ?? ''}
      lines={words?.lines ?? []}
      confirmLabel={words?.confirmLabel ?? ''}
      cancelLabel={words?.cancelLabel}
      working={flow.working}
      problem={flow.problem}
      blocked={words?.blocked}
      onConfirm={flow.confirm}
      onCancel={flow.cancel}
      returnFocusTo={returnFocusTo}
      fallbackFocusTo={fallbackFocusTo}
    />
  )
}

/** The Household card: initials, name, role chip — and "+ Add household member" for the primary resident. */
export function Household({ m }: { m: ProfileModel }) {
  const rows = m.members.rows.filter((row) => row.queue !== 'refused')
  const primary = m.manage.allowed === true
  const invites = useInvites({ shown: rows.filter((row) => row.queue === null).map((row) => row.item), listFetchedAt: m.membersFetchedAt })
  const [open, setOpen] = useState<Member | null>(null)
  // The member whose row opened the latest sheet: it gets focus back when that sheet (or the one it led to) closes.
  const [acting, setActing] = useState<Member | null>(null)
  const memberRow = useRef<View>(null)
  const addRow = useRef<View>(null)
  const removal = m.manage.removal
  const levelChange = m.manage.levelChange
  const hoursChange = m.manage.hoursChange
  const opened = open ? rows.find((row) => sameMember(open, row.item))?.item ?? null : null

  // Asking to cancel an invite opens its own sheet: this one steps aside first, so two sheets never stack.
  useEffect(() => {
    if (invites.asking) setOpen(null)
  }, [invites.asking])

  return (
    <>
      <SectionLabel>Household</SectionLabel>
      <Card pad={13}>
        {rows.map((row: Row<Member>, i) => (
          <MemberRow
            key={row.key}
            member={row.item}
            tag={m.fixture?.board.memberTags[String(row.item.id)] ?? memberTag(row.item)}
            onPress={() => { setActing(row.item); setOpen(row.item) }}
            divider={primary || i < rows.length - 1}
            rowRef={sameMember(acting, row.item) ? memberRow : undefined}
          />
        ))}
        {primary ? <AddRow rowRef={addRow} label="Add household member" onPress={() => go(ROUTES.member)} /> : null}
      </Card>
      <MemberSheet
        m={m}
        member={opened}
        invites={invites}
        onClose={() => setOpen(null)}
        onRemove={(member) => { setOpen(null); removal.ask(member) }}
        onAccess={(member) => { setOpen(null); levelChange.ask({ member, level: isLevel(member.access_level) ? member.access_level : 'member' }) }}
        onHours={(member) => { setOpen(null); hoursChange.ask({ member, window: windowOf(member) ?? { ...DEFAULT_WINDOW } }) }}
      />
      <RemoveMemberSheet m={m} returnFocusTo={memberRow} fallbackFocusTo={[addRow]} />
      <LevelSheet m={m} returnFocusTo={memberRow} fallbackFocusTo={[addRow]} />
      <HoursSheet m={m} returnFocusTo={memberRow} fallbackFocusTo={[addRow]} />
      <CancelInviteSheet control={invites} returnFocusTo={memberRow} fallbackFocusTo={[addRow]} />
    </>
  )
}

/** `.add-row` — "+ Add household member", and the same row at the foot of the vehicles', pets' and contacts' cards. */
function AddRow({ label, onPress, rowRef }: { label: string; onPress: () => void; rowRef?: Ref<View> }) {
  return (
    <Pressable ref={rowRef} onPress={onPress} role="link" aria-label={label} hitSlop={{ top: size[8], bottom: size[10] }} style={s.addRow}>
      <View style={s.plus}><Icon name="qty-btn-2" size={icon[13]} color={ink.action} /></View>
      <Text {...font.inter700_12} color={ink.action}>{label}</Text>
    </Pressable>
  )
}

/* ---- vehicles & pets ------------------------------------------------------------------------------ */

function Pill({ glyph, text, onPress, label }: { glyph: IconName; text: string; onPress?: () => void; label: string }) {
  const body = (
    <>
      <Icon name={glyph} size={icon[14]} color={ink.action} />
      <Text {...font.inter600_11} color={ink.body}>{text}</Text>
    </>
  )
  return onPress ? (
    <Pressable onPress={onPress} role="button" aria-label={label} style={({ pressed }) => [s.pill, pressed ? s.pressedOnTint : null]}>{body}</Pressable>
  ) : (
    <View style={s.pill} accessible aria-label={label}>{body}</View>
  )
}

const onServer = <T extends { id: number | string }>(row: Row<T>): boolean => row.queue === null && /^\d+$/.test(String(row.item.id))

/** The screen a row opens to change it — `?id=` the server's own id. */
const editPath = (route: string, id: number | string): string => `${route}?id=${encodeURIComponent(String(id))}`

/**
 * Vehicles & pets (M50 §5.3) — the vehicles and the pets as pills; tapping one opens its own screen to change or remove
 * it (never a remove on the row), and "+ Add vehicle" and "+ Add pet" sit at the card's foot in the "+ Add household
 * member" row's style — for whoever the server lets change them. The board draws its own pet, in fidelity mode only.
 */
function VehiclesAndPets({ m }: { m: ProfileModel }) {
  const vehicles = m.vehicles.rows.filter((row) => row.queue !== 'refused')
  // M57 §6: pets are out of the interface (flags.householdPets); the server keeps every pet row.
  const pets = flags.householdPets ? m.pets.rows.filter((row) => row.queue !== 'refused') : []
  const adds = m.mayEdit && !m.fixture
  const pills = vehicles.length + pets.length > 0 || !!m.fixture
  if (!pills && !adds) return null
  return (
    <>
      <SectionLabel>{flags.householdPets || m.fixture ? 'Vehicles & pets' : 'Vehicles'}</SectionLabel>
      <Card pad={13}>
        {pills ? (
          <View style={adds ? s.pillsOverAdd : s.pills}>
            {vehicles.map((row: Row<Vehicle>) => (
              <Pill
                key={row.key}
                glyph="item-tag"
                text={vehicleLine(row.item)}
                label={adds && onServer(row) ? `${vehicleLine(row.item)}. Edit` : vehicleLine(row.item)}
                onPress={adds && onServer(row) ? () => go(editPath(ROUTES.vehicle, row.item.id)) : undefined}
              />
            ))}
            {pets.map((row: Row<Pet>) => (
              <Pill
                key={row.key}
                glyph="item-tag-2"
                text={petLine(row.item)}
                label={adds && onServer(row) ? `${petLine(row.item)}. Edit` : petLine(row.item)}
                onPress={adds && onServer(row) ? () => go(editPath(ROUTES.pet, row.item.id)) : undefined}
              />
            ))}
            {m.fixture ? <Pill glyph="item-tag-2" text={m.fixture.board.pet} label={m.fixture.board.pet} /> : null}
          </View>
        ) : null}
        {adds ? (
          <>
            <AddRow label="Add vehicle" onPress={() => go(ROUTES.vehicle)} />
            {flags.householdPets ? <AddRow label="Add pet" onPress={() => go(ROUTES.pet)} /> : null}
          </>
        ) : null}
      </Card>
    </>
  )
}

/* ---- emergency contact ---------------------------------------------------------------------------- */

/**
 * The household's emergency contacts (M50 §5.4) — the design draws one; a household may list several, and the card grows
 * with them (none is capped). Tapping one opens it to change or remove it, in the same four-field form that adds one, and
 * "+ Add emergency contact" sits at the foot — for whoever the server lets change them.
 */
function EmergencyContact({ m }: { m: ProfileModel }) {
  const rows = m.contacts.rows.filter((row) => row.queue !== 'refused')
  const adds = m.mayEdit && !m.fixture
  if (rows.length === 0 && !adds) return null
  return (
    <>
      <SectionLabel>Emergency contact</SectionLabel>
      <Card pad={13}>
        {rows.map((row: Row<Contact>) => (
          <InfoRow
            key={row.key}
            glyph="cc-row"
            text={contactLine(row.item)}
            label={adds && onServer(row) ? `${contactLine(row.item)}. Edit` : contactLine(row.item)}
            onPress={adds && onServer(row) ? () => go(editPath(ROUTES.contact, row.item.id)) : undefined}
          />
        ))}
        {adds ? <AddRow label="Add emergency contact" onPress={() => go(ROUTES.contact)} /> : null}
      </Card>
    </>
  )
}

/* ---- the screen ----------------------------------------------------------------------------------- */

export default function ProfileScreen() {
  const m = useProfile()
  useFidelityReady(true)

  return (
    <View style={s.screen}>
      {/* The gear says what it is (M50 §6.12): "Settings" under it — a deliberate departure from the board's bare gear. */}
      <AppBar title="Profile" back={false} right={{ icon: 'side-btn-9', label: 'Settings', caption: 'Settings', onPress: () => go(ROUTES.settings) }} />
      <ScrollView
        style={s.fill}
        contentContainerStyle={s.bodyPad}
        refreshControl={m.fixture ? undefined : <RefreshControl refreshing={m.refreshing} onRefresh={m.refresh} tintColor={c(ink.action)} />}
      >
        <Hero m={m} />
        {m.denied ? (
          <EmptyState tone="light" icon="tab-profile" title="Your household isn't open to your account" body={m.deniedMessage} action={{ label: 'Try again', onPress: m.refresh }} />
        ) : (
          <>
            <Details m={m} />
            {m.keptBy ? null : (
              <>
                <Household m={m} />
                <VehiclesAndPets m={m} />
                <EmergencyContact m={m} />
              </>
            )}
          </>
        )}
      </ScrollView>
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface },
  fill: { flex: 1 },
  // .body-pad (Profile's: 18 above). The board's own 20 below is empty space that does not fit 390 x 844 — the
  // design overflows there by 15, all of it padding (Stage 0 §4.2) — so 4 is kept: nothing visible moves.
  bodyPad: { paddingTop: space[18], paddingHorizontal: space[20], paddingBottom: space[4] },
  // Pressed: a row's, a pill's or a button's fill one step darker, as one piece (M50 §6.7a) — never a fade drawn
  // through it. A white row darkens to the pale navy; what is already pale navy, to the next navy; the gold, deeper gold.
  pressed: PRESSED,
  pressedOnTint: { backgroundColor: fill.divider },
  pressedGold: { backgroundColor: fill.goldIconTile },

  // .profile-hero · .p-avatar-wrap · .p-avatar · .p-edit · .profile-name · .role-tag
  hero: { flexDirection: 'row', alignItems: 'center', gap: space[14] },
  avatarWrap: { width: size[64], height: size[64], flexShrink: 0 },
  avatar: { width: size[64], height: size[64], borderRadius: corner.round(size[64]), backgroundColor: fill.tint, borderWidth: stroke['2.5'], borderColor: line.focus, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  avatarImage: { width: size[64], height: size[64] },
  pencil: { position: 'absolute', right: space['-2'], bottom: space['-2'], width: size[22], height: size[22], borderRadius: corner.round(size[22]), backgroundColor: fill.primary, borderWidth: stroke[2], borderColor: line.onDarkSolid, alignItems: 'center', justifyContent: 'center' },
  heroText: { flex: 1, minWidth: 0 },
  place: { marginTop: space[2] },
  tags: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: space[6], marginTop: space[6] },
  roleTag: { backgroundColor: fill.tint, borderRadius: corner[20], paddingVertical: space[3], paddingHorizontal: space[9] },
  // My e-Pass (M47 §6d): the role tag's own pill, in the design's gold — its colour for what is tapped — with Home's
  // e-Pass glyph; on the role tag's line, so the hero is no taller and its address line keeps the whole width
  epassTag: { flexDirection: 'row', alignItems: 'center', gap: space[4], backgroundColor: fill.goldTint, borderRadius: corner[20], paddingVertical: space[3], paddingHorizontal: space[9] },

  // .info-row
  infoRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[6] },
  infoText: { flex: 1, minWidth: 0 },

  // .member-row · .m-avatar · .m-info · .m-tag — every row ruled underneath but the very last element of the card
  memberRow: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[7] },
  memberRowRule: { flexDirection: 'row', alignItems: 'center', gap: space[11], paddingVertical: space[7], borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  memberAvatar: { width: size[34], height: size[34], borderRadius: corner.round(size[34]), backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center', flexShrink: 0 },
  memberName: { flex: 1, minWidth: 0 },
  memberTag: { backgroundColor: fill.tint, borderRadius: corner[20], paddingVertical: space[3], paddingHorizontal: space[8] },

  // .add-row · .plus
  addRow: { flexDirection: 'row', alignItems: 'center', gap: space[10], paddingTop: space[7], paddingBottom: space[1] },
  plus: { width: size[26], height: size[26], borderRadius: corner.round(size[26]), backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center' },

  // .tag-list · .item-tag — and, with the "+ Add …" rows under it, ruled off from them as the member rows are
  pills: { flexDirection: 'row', flexWrap: 'wrap', gap: space[8] },
  pillsOverAdd: { flexDirection: 'row', flexWrap: 'wrap', gap: space[8], paddingBottom: space[10], borderBottomWidth: stroke[1], borderBottomColor: line.hairline },
  pill: { flexDirection: 'row', alignItems: 'center', gap: space[6], backgroundColor: fill.tint, borderRadius: corner[20], paddingVertical: space[7], paddingHorizontal: space[11] },

  // Inside the sheets (no board draws them).
  sheetActions: { marginTop: space[14], gap: space[8] },
  levels: { gap: space[8], marginTop: space[12] },
  level: { borderRadius: corner[12], borderWidth: stroke[1], borderColor: line.hairline, backgroundColor: fill.surface, paddingHorizontal: space[12], paddingVertical: space[10] },
  levelOn: { borderRadius: corner[12], borderWidth: stroke[2], borderColor: line.active, backgroundColor: fill.tint, paddingHorizontal: space[12], paddingVertical: space[10] },
  levelLine: { marginTop: space[2] },
  // A Helper's days and hours (8 Oct 2026): the chips, the two times, the end date and the pause, 12 apart.
  hours: { gap: space[12], marginTop: space[12] },
  stepRow: { flexDirection: 'row', alignItems: 'center', gap: space[8] },
  stepLabel: { flex: 1, minWidth: 0 },
  stepButton: { width: size[36], height: size[36], borderRadius: corner.round(size[36]), backgroundColor: fill.tint, alignItems: 'center', justifyContent: 'center' },
  stepValue: { minWidth: size[96], alignItems: 'center' },
  switchRow: { flexDirection: 'row', alignItems: 'center', gap: space[12] },
  switchText: { flex: 1, minWidth: 0 },
})
