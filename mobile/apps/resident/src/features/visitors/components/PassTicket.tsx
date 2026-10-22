/**
 * THE PASS, DRAWN AS A TICKET (M12 §2.8; M18F) — the card on the pass screen AND the picture the visitor receives:
 * one drawing, so what the resident sees is exactly what is sent, and the two can never drift apart again.
 *
 * One SVG on a 720-wide grid, scaled to the room it is given: a navy head with the estate and the lot, a perforation,
 * the visitor, the day and the window on the estate's clock, the QR on white with its full quiet zone, the code
 * beneath it, and one short line on how the gate reads it.
 *
 * NOTHING IS CUT (M18F §5). Every line is broken on its words to the width it has (`wrap`) and the ticket grows to hold
 * them — a long estate name, a long visitor name, a pass over several days. The footer used to be a single line wider
 * than the ticket and ran off both ends, on the screen and in the picture visitors were sent.
 *
 * `toPng()` hands back the same drawing as a PNG (react-native-svg's own `toDataURL`, drawn natively at 1080 px
 * across), on a SOLID background — a messenger paints a transparent picture black, and a QR on black does not scan.
 * The QR is navy-900 on white at 480 of the 720 across: large enough to survive a messenger's re-compression and a
 * photo of a phone screen at arm's length.
 */

import { forwardRef, useImperativeHandle, useMemo, useRef } from 'react'
import { View } from 'react-native'
import Svg, { Circle, G, Line, Path, Rect, Text as SvgText } from 'react-native-svg'
import { c } from '@gs/core'
import { TICKET_QR, TICKET_W, ticketLayout, type TicketBlock, type Ticket } from '../passTicket'
import { qrPathOf } from '../qrPicture'

const W = TICKET_W
/** Pixels across the exported PNG: crisp on any phone, under what messengers re-compress to. */
export const TICKET_PNG_WIDTH = 1080
const QR_SIZE = TICKET_QR

export type PassTicketHandle = { toPng: () => Promise<string | null> }

type SvgWithData = { toDataURL?: (callback: (base64: string) => void, options?: { width: number; height: number }) => void }

type Block = TicketBlock

function Lines({ block, x, anchor, ...text }: { block: Block; x: number; anchor?: 'middle'; fill: string; fontFamily: string; fontSize: number; letterSpacing?: number }) {
  return (
    <>
      {block.lines.map((line, i) => (
        <SvgText key={i} x={x} y={block.first + i * block.step} textAnchor={anchor} {...text}>{line}</SvgText>
      ))}
    </>
  )
}

export const PassTicket = forwardRef<PassTicketHandle, { ticket: Ticket; width: number }>(function PassTicket({ ticket, width }, ref) {
  const svg = useRef<SvgWithData>(null)
  const qr = useMemo(() => qrPathOf(ticket.token), [ticket.token])
  const at = useMemo(() => ticketLayout(ticket), [ticket])
  const H = at.height

  useImperativeHandle(ref, () => ({
    toPng: () =>
      new Promise<string | null>((resolve) => {
        const node = svg.current
        if (!node?.toDataURL) return resolve(null)
        const timer = setTimeout(() => resolve(null), 4000)
        try {
          node.toDataURL(
            (base64) => {
              clearTimeout(timer)
              resolve(typeof base64 === 'string' && base64.length > 0 ? base64.replace(/^data:image\/png;base64,/, '') : null)
            },
            { width: TICKET_PNG_WIDTH, height: Math.round((TICKET_PNG_WIDTH * H) / W) },
          )
        } catch {
          clearTimeout(timer)
          resolve(null)
        }
      }),
  }))

  const qrX = (W - QR_SIZE) / 2
  const scale = QR_SIZE / qr.count

  return (
    <View role="img" aria-label={ticket.label} style={{ width, height: (width * H) / W }}>
      <Svg ref={svg as never} width={width} height={(width * H) / W} viewBox={`0 0 ${W} ${H}`}>
        {/* The solid page behind the ticket: never transparent. */}
        <Rect x={0} y={0} width={W} height={H} fill={c('navy-100')} />
        <Rect x={20} y={20} width={W - 40} height={H - 40} rx={36} fill={c('white')} />

        {/* The head: who it is from and where it opens. */}
        <Path d={`M20 56 a36 36 0 0 1 36 -36 H${W - 56} a36 36 0 0 1 36 36 V${at.headEnd} H20 Z`} fill={c('navy-700')} />
        <SvgText x={60} y={82} fill={c('amber-500')} fontFamily="Inter-Bold" fontSize={22} letterSpacing={2}>{`GEMINISECURE · ${ticket.kind.toUpperCase()}`}</SvgText>
        <Lines block={at.estate} x={60} fill={c('white')} fontFamily="Poppins-Bold" fontSize={44} />
        {at.lot ? <Lines block={at.lot} x={60} fill={c('white')} fontFamily="Inter-SemiBold" fontSize={30} /> : null}
        <SvgText x={60} y={at.tagline} fill={c('navy-100')} fontFamily="Inter-Medium" fontSize={22}>{'Visitor pass: signed by the estate'}</SvgText>

        {/* The perforation: two notches, inside the page, and a dashed tear line. */}
        <Circle cx={20} cy={at.perf} r={20} fill={c('navy-100')} />
        <Circle cx={W - 20} cy={at.perf} r={20} fill={c('navy-100')} />
        <Line x1={56} y1={at.perf} x2={W - 56} y2={at.perf} stroke={c('navy-200')} strokeWidth={3} strokeDasharray="14 12" />

        {/* Who, when. */}
        <SvgText x={60} y={at.visitorLabel} fill={c('slate-600')} fontFamily="Inter-Bold" fontSize={22} letterSpacing={1.5}>{'VISITOR'}</SvgText>
        <Lines block={at.visitor} x={60} fill={c('navy-900')} fontFamily="Poppins-Bold" fontSize={46} />
        <Lines block={at.when} x={60} fill={c('navy-900')} fontFamily="Inter-SemiBold" fontSize={30} />

        {/* The QR: navy on white, its full quiet zone inside the white square. */}
        <Rect x={qrX} y={at.qrY} width={QR_SIZE} height={QR_SIZE} fill={c('white')} />
        <G transform={`translate(${qrX} ${at.qrY}) scale(${scale})`}>
          <Path d={qr.d} fill={c('navy-900')} />
        </G>

        {/* The code beneath it — what the guard types if the scan will not take. */}
        <SvgText x={W / 2} y={at.code} fill={c('navy-900')} fontFamily="IBMPlexMono-Medium" fontSize={54} letterSpacing={6} textAnchor="middle">{ticket.code}</SvgText>
        <Lines block={at.footer} x={W / 2} anchor="middle" fill={c('slate-600')} fontFamily="Inter-Medium" fontSize={22} />
      </Svg>
    </View>
  )
})
