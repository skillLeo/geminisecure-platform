/**
 * /visitors/pass/[id] — one pass: its QR code, its short code, its window, sharing it and
 * cancelling it. `/visitors/pass/e-pass` is the resident's OWN pass for the gate: My e-Pass.
 *
 * RESTYLED — no board draws this screen. It is drawn to belong with the rebuilt ones: the app bar, the card, the
 * buttons and the info card of design/parts, every value from design/tokens. The QR block takes the resident boards'
 * success pattern: the words centred over the code (the kind as board 31 labels its confirmation code, the name in the
 * pattern's Poppins 20, the window in its 12 · slate-500 line), the QR in board 31's white rounded box
 * (components/PassQr), the code as board 31 writes its confirmation code, and the notes in the pattern's info card.
 * What the screen does is unchanged.
 *
 * THE QR IS DRAWN ON THE PHONE from the signed token the phone already holds, so it is there with
 * no connection — and the brightness is turned up while it is shown, because a gate scanner in
 * sunlight reads a dim screen badly. Nothing here can make a pass look valid when it is not: a used,
 * cancelled or expired pass shows its state INSTEAD of a code.
 *
 * SENDING it sends the pass as a TICKET-LIKE PICTURE — estate, lot, visitor, day, window, the QR and the code (R6;
 * M12 §2.8) — with the invitation as its caption (M18F §4). The ticket at the top IS that picture: one card, one QR
 * on the page (M18F §2 — the second card, "What your visitor receives", and its second QR are gone). The words alone
 * are one tap below, for a text message.
 *
 * CANCELLING is asked first on every platform — a browser used to cancel on the first tap — in a ConfirmSheet
 * naming the visitor ("Cancel Jane Brown's pass?"). The server's refusal stays in the sheet.
 *
 * MY E-PASS IS HALF AN HOUR AT A TIME (M47 §6): the QR is the half hour this moment is in, from the day of them the
 * phone keeps (passes.epassNow), and the window under the name says which. Its typed code is shown under the QR again
 * (the user, 9 Oct 2026: a guard at night with a scanner that will not read the screen needs it), knowing it opens the
 * gate for the pass's whole window, unlike the QR. The user's one line says what the resident needs; when the day of
 * half hours is spent, the screen says to connect once.
 *
 * Pulling down collects the pass afresh (usePass.refresh); the e-Pass is also collected as each half hour ends, while
 * the app is open (EpassKeeper). With the e-Pass's content the page fits a 390 × 844 phone and nothing moves; a
 * visitor's ticket and what is done with it run longer, and scroll.
 */

import { useEffect, useRef } from 'react'
import { Platform, RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native'
import { useLocalSearchParams } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import * as Brightness from 'expo-brightness'
import * as Clipboard from 'expo-clipboard'
import { ConfirmSheet, EmptyState, ErrorView, Pressable, Skeleton, Text, aboveGestureBar, now, useConfirmFlow, useFidelityReady } from '@gs/core'
import { normalisePhone } from '@gs/core/lib'
import { AppBar, Card, DetailLines, InfoCard, PrimaryButton, SecondaryButton } from '@/design/parts'
import { fill, font, ink, line, radius, size, space, stroke } from '@/design/tokens'
import { PassQr } from '../components/PassQr'
import { PassTicket, type PassTicketHandle } from '../components/PassTicket'
import { TICKET_QR, TICKET_W } from '../passTicket'
import { CATEGORY_LABEL, arrivalWords, passCancelSheet, passOutcome, windowText, type Pass } from '../passes'
import { usePass } from '../useVisitors'

/** My e-Pass, in the user's own words (9 Oct 2026), word for word. */
const EPASS_LINE = 'Show this at the gate. It works with no connection. Each code lasts 30 minutes and the next one takes over by itself. Open the app online once a day so your phone always has current codes.'

/** Said BEFORE the tap: what goes (M12 §2.8) — the ticket above, with the invitation as its caption. */
const SEND_HINT = Platform.OS === 'web'
  ? 'Sends the times and the gate code.'
  : 'Sends the pass above as a picture, WhatsApp, Messages or e-mail, with the times and the gate code as its caption.'

/** The code's touch area, grown to 44 points tall around its line. */
const CODE_REACH = { top: space[12], bottom: space[12], left: space[12], right: space[12] }

/** What the pass says beyond its window, in the success pattern's detail card — and only when it says any of it. */
function factsOf(pass: Pass, isEpass: boolean): { label: string; value: string }[] {
  if (!(pass.purpose || pass.vehicle_plate || pass.no_vehicle || pass.visitor_phone)) return []
  const facts: { label: string; value: string }[] = []
  if (pass.purpose) facts.push({ label: 'Purpose', value: pass.purpose })
  // Walk in · Drive in · Ride in, as Invite visitor asks it (M50 §6.10) — never "On foot or by taxi".
  const arriving = arrivalWords(pass)
  if (arriving) facts.push({ label: 'Arriving', value: arriving })
  // One phone format everywhere (M50 §6.11): "+1 876 555 0148".
  if (pass.visitor_phone) facts.push({ label: 'Phone', value: normalisePhone(pass.visitor_phone) ?? pass.visitor_phone })
  if (!isEpass) facts.push({ label: 'Shared', value: Number(pass.share_count) === 0 ? 'Not yet' : `${pass.share_count} time${Number(pass.share_count) === 1 ? '' : 's'}` })
  return facts
}

export default function PassScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>()
  const m = usePass(id)
  const insets = useSafeAreaInsets()
  const { width } = useWindowDimensions()
  useFidelityReady(true)

  const pass = m.pass
  const outcome = pass ? passOutcome(pass, now()) : null
  const usable = !!pass && pass.status === 'active' && new Date(pass.valid_to).getTime() >= now().getTime()

  // Full brightness while a usable code is on screen; whatever it was before, afterwards.
  useEffect(() => {
    if (!usable || Platform.OS === 'web') return
    let previous: number | null = null
    let cancelled = false
    void (async () => {
      try {
        previous = await Brightness.getBrightnessAsync()
        if (!cancelled) await Brightness.setBrightnessAsync(1)
      } catch {
        previous = null // not permitted on this phone: the code is still there, just not brighter
      }
    })()
    return () => {
      cancelled = true
      if (previous !== null) void Brightness.setBrightnessAsync(previous).catch(() => undefined)
    }
  }, [usable])

  // Asked on EVERY platform, in a sheet naming the visitor; it closes when the pass is cancelled (or saved to be).
  // Focus comes back to the box that holds "Cancel this pass" when the sheet is closed without a yes.
  const cancelButton = useRef<View>(null)
  const cancelling = useConfirmFlow<true>({
    send: async () => {
      const result = await m.cancel()
      return result.outcome === 'refused' ? { ok: false, message: result.message } : { ok: true }
    },
  })
  const cancelWords = pass ? passCancelSheet(pass.visitor_name, m.online) : null

  // The ticket: the screen's width less its padding (M18F §5 — inside the padding at every width, from 360 up), and at
  // most half its 720 grid — 360 points, no wider than a ticket reads well.
  const ticket = useRef<PassTicketHandle>(null)
  const ticketWidth = Math.min(TICKET_W / 2, width - 2 * space[20])
  const ticketed = usable && !m.isEpass && !!m.ticket && Platform.OS !== 'web'
  // The QR on the card is as large as a visitor's ticket draws its own on this phone: 480 of the ticket's 720.
  const qrSide = Math.round((ticketWidth * TICKET_QR) / TICKET_W)
  const copyCode = () => {
    if (pass) void Clipboard.setStringAsync(pass.code)
  }
  const facts = pass ? factsOf(pass, m.isEpass) : []
  const sending = m.busy !== null

  return (
    <View style={s.screen}>
      <AppBar title={m.isEpass ? 'My e-Pass' : 'Visitor pass'} fallback="/(tabs)/visitors" />
      <ScrollView style={s.fill} contentContainerStyle={[s.body, { paddingBottom: aboveGestureBar(space[20], insets.bottom) }]} refreshControl={<RefreshControl refreshing={false} onRefresh={m.refresh} tintColor={fill.primary} />}>
        {/* Loading is never "not found" (M50 §6.4): the pass's own shapes in pale grey — the card's lines and its QR. */}
        {m.status === 'loading' ? (
          <Card style={s.pass}>
            <View role="progressbar" aria-label="Loading the pass" style={s.skeleton}>
              <Skeleton tone="light" width="36%" height={size[10]} />
              <Skeleton tone="light" width="64%" height={size[20]} />
              <Skeleton tone="light" width="72%" height={size[12]} />
              <Skeleton tone="light" width={qrSide} height={qrSide} radius={radius.card} style={s.skeletonQr} />
            </View>
          </Card>
        ) : null}
        {m.status === 'error' ? <ErrorView tone="light" message="This pass could not be loaded." onRetry={m.refresh} /> : null}
        {m.status === 'missing' ? (
          m.off ? (
            // A Helper's e-Pass the household paused or ended, or one closed for the next day: the server's own words.
            <EmptyState tone="light" icon="tab-visitors" title="Your e-Pass is off" body={m.off} />
          ) : m.ranOut ? (
            <EmptyState tone="light" icon="tab-visitors" title="Your e-Pass ran out" body="This phone keeps a day of it, and that day is over. Connect once to collect the next." />
          ) : (
            <EmptyState tone="light" icon="tab-visitors" title={m.isEpass ? 'Your e-Pass is not on this phone yet' : 'This pass is not on this phone'} body={m.isEpass ? 'Connect once and it is kept here for the day: after that it opens with no connection.' : 'It may be older than 30 days, or this phone has not loaded it yet. Pull down to check again.'} />
          )
        ) : null}

        {pass && outcome ? (
          <>
            {/*
              ONE PASS, ONE DESIGN (M18F §2). For a visitor's pass that still opens the gate, the card on this screen IS
              the ticket the visitor is sent — one drawing, one QR, nothing clipped — so what the resident sees is what
              goes. The resident's own e-pass, a pass that no longer opens the gate, and a browser (which cannot turn
              the drawing into a picture) keep the card below.
            */}
            {ticketed ? (
              <View style={s.ticketBox}>
                <PassTicket ref={ticket} ticket={m.ticket!} width={ticketWidth} />
                <Pressable onPress={copyCode} role="button" aria-label={`Pass code ${pass.code.split('').join(' ')}. Tap to copy`} hitSlop={space[8]} style={s.copyRow}>
                  <Text {...font.inter600_12_5} color={ink.action} style={s.underline}>{`Copy the code ${pass.code}`}</Text>
                </Pressable>
              </View>
            ) : (
              <Card style={s.pass}>
                <Text {...font.inter700_9_5} color={ink.secondary} align="center">{(CATEGORY_LABEL[pass.category] ?? 'Pass').toUpperCase()}</Text>
                <View style={s.name}><Text {...font.poppins600_20} color={ink.heading} align="center">{pass.visitor_name}</Text></View>
                <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{windowText(pass.valid_from, pass.valid_to, now())}</Text></View>

                {usable && m.isEpass ? (
                  <>
                    <View style={s.qr}><PassQr token={pass.token} size={qrSide} label="QR code for your e-Pass" /></View>
                    {/*
                      THE TYPED CODE UNDER THE QR, PUT BACK (the user, 9 Oct 2026): for a gate whose scanner cannot read
                      the screen, at night, in the rain. Shown, not copyable: unlike the QR it is the pass's own code and
                      opens the gate for the pass's whole window, so it is for the guard to read, not to pass around.
                    */}
                    <Text {...font.inter700_16_ls1_5} color={ink.heading} aria-label={`Gate code ${pass.code.split('').join(' ')}`}>{pass.code}</Text>
                    <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{'If the QR will not scan, the guard can type this code.'}</Text></View>
                    {/* A Helper's days and hours (8 Oct 2026): the gate admits them on it only then. */}
                    {m.hours ? <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{m.hours}</Text></View> : null}
                  </>
                ) : usable ? (
                  <>
                    <View style={s.qr}><PassQr token={pass.token} size={qrSide} label={`QR code for ${pass.visitor_name}'s pass`} /></View>
                    <Pressable onPress={copyCode} role="button" aria-label={`Pass code ${pass.code.split('').join(' ')}. Tap to copy`} hitSlop={CODE_REACH}>
                      <Text {...font.inter700_16_ls1_5} color={ink.heading}>{pass.code}</Text>
                    </Pressable>
                    <View style={s.line}><Text {...font.inter400_12_lh1_5} color={ink.secondary} align="center">{'If the QR will not scan, the guard can type this code.'}</Text></View>
                  </>
                ) : (
                  <View style={s.ended} role="status">
                    <Text {...font.poppins600_16} color={ink.heading} align="center">{outcome.word}</Text>
                    <View style={s.endedLine}><Text {...font.inter400_12_lh1_5} color={ink.label} align="center">{`${outcome.detail}. This pass no longer opens the gate, so its code is not shown.`}</Text></View>
                  </View>
                )}
              </Card>
            )}

            {/* The e-Pass's own line already says it works offline; a second card would say it again. */}
            {m.stale && usable && !m.isEpass ? (
              <View style={s.info} role="status">
                <InfoCard icon="confirm-note" text={`${m.staleLead}: this is the pass saved on this phone. It still works at the gate, which checks its signature without the internet.`} />
              </View>
            ) : null}
            {m.note ? <View style={s.note}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{m.note}</Text></View> : null}

            {facts.length > 0 ? <DetailLines lines={facts} /> : null}

            {/*
              The user's words, on the screen and nowhere else (9 Oct 2026: no link, no sheet). True to what the phone
              does: each code opens the gate for 30 minutes, and every time the app opens with a connection it keeps the
              next day of them (48 half hours), so the gate works without a signal for about a day after it last connected.
            */}
            {m.isEpass && usable ? (
              <View style={s.info}>
                <InfoCard icon="confirm-note" text={EPASS_LINE} />
              </View>
            ) : null}

            {/* M18F §2.1 and M18D §1: the one primary action, the words-only link beneath it, and — apart — cancelling. */}
            {usable && !m.isEpass ? (
              <View style={s.actions}>
                <PrimaryButton label="Send to my visitor" onPress={() => m.share('picture', () => ticket.current?.toPng() ?? Promise.resolve(null))} loading={m.busy === 'share'} />
                <View style={s.hint}><Text {...font.inter400_11_lh1_5} color={ink.secondary} align="center">{SEND_HINT}</Text></View>
                <Pressable onPress={() => m.share('text')} disabled={sending} role="button" aria-label="Send as words only (for SMS)" aria-disabled={sending} accessibilityHint={sending ? 'Wait a moment: this pass is being sent.' : undefined} style={[s.link, sending ? s.off : null]}>
                  <Text {...font.inter700_12} color={ink.action}>{'Send as words only (for SMS)'}</Text>
                </Pressable>
                <View style={s.apart} />
                <View ref={cancelButton} collapsable={false}>
                  <SecondaryButton label="Cancel this pass" onPress={() => cancelling.ask(true)} loading={m.busy === 'cancel'} />
                </View>
                <View style={s.hintBelow}><Text {...font.inter400_11_lh1_5} color={ink.secondary} align="center">{'To change the day or time, cancel this pass and send a new one: a signed pass cannot be edited.'}</Text></View>
              </View>
            ) : null}
          </>
        ) : null}
      </ScrollView>
      <ConfirmSheet
        open={cancelling.open && !!cancelWords}
        title={cancelWords?.title ?? ''}
        lines={cancelWords?.lines ?? []}
        confirmLabel={cancelWords?.confirmLabel ?? ''}
        cancelLabel={cancelWords?.cancelLabel}
        working={cancelling.working}
        problem={cancelling.problem}
        onConfirm={cancelling.confirm}
        onCancel={cancelling.cancel}
        returnFocusTo={cancelButton}
      />
    </View>
  )
}

const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: fill.surface, overflow: 'hidden' },
  fill: { flex: 1 },
  // .body-pad (the boards' app-bar screens): 18 above, 20 at the sides; its foot is set by the screen
  body: { paddingTop: space[18], paddingHorizontal: space[20] },

  // The visitor's ticket, and the link that copies its code
  ticketBox: { alignItems: 'center', marginBottom: space[12] },
  copyRow: { minHeight: size[44], justifyContent: 'center', marginTop: space[4] },
  underline: { textDecorationLine: 'underline' },

  // The pass: the success pattern's words, centred — the name 4 under its kind, each line 5 under what it follows
  pass: { alignItems: 'center' },
  // The loading card (M50 §6.4): its three lines centred as the pass's are, 8 apart, then the QR's square under them
  skeleton: { alignSelf: 'stretch', alignItems: 'center', gap: space[8] },
  skeletonQr: { marginTop: space[8] },
  name: { marginTop: space[4] },
  line: { marginTop: space[5] },
  // board 31's .qr-box: 16 under the words (the board's 14 + 4 under its card), 10 above the code
  qr: { marginTop: space[16], marginBottom: space[10] },
  // A pass that no longer opens the gate: board 31's .booking-card — navy-100, corners of 16, 16 inside
  ended: { alignSelf: 'stretch', backgroundColor: fill.tint, borderRadius: radius.card, padding: space[16], marginTop: space[16] },
  endedLine: { marginTop: space[4] },

  // The success pattern's info card, and a note when something did not go
  info: { marginBottom: space[12] },
  note: { marginHorizontal: space[2], marginBottom: space[12] },

  // What is done with a visitor's pass: send it, send its words, and — apart — cancel it
  actions: { marginTop: space[4] },
  hint: { marginTop: space[8] },
  link: { minHeight: size[44], alignItems: 'center', justifyContent: 'center' },
  off: { opacity: 0.5 },
  apart: { height: stroke[1], backgroundColor: line.hairline, marginVertical: space[14] },
  hintBelow: { marginTop: space[10] },
})
