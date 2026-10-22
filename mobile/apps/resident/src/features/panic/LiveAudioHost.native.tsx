/**
 * THE ONE RECORDER FOR "SHARE LIVE AUDIO" (M50 §4.2 #1) — mounted once at the app's root, so the audio goes on when
 * the resident leaves the panic screen, locks the phone or puts the app away. What it records for is liveAudio.ts's
 * `wanted`; what is true is told back with `setLiveAudioState`.
 *
 * Each piece: PIECE_MS of AAC in MP4, mono, 16 kHz, 32 kbit/s — about 20 KB, small enough for a weak signal, and a
 * format the console's browser plays — handed to the queue the moment it ends (`duress.audio.store`, multipart, with
 * its number), and the next started at once.
 */

import { useEffect } from 'react'
import { AudioQuality, IOSOutputFormat, getRecordingPermissionsAsync, requestRecordingPermissionsAsync, setAudioModeAsync, useAudioRecorder, type RecordingOptions } from 'expo-audio'
import { offer, outbox, submit } from '@/offline/runtime'
import { PIECE_MS, setLiveAudioState, takeSeq, useWantedAudio, wantLiveAudio, type AudioTarget } from './liveAudio'

export const AUDIO_ENDPOINT = 'duress.audio.store'

const PIECE: RecordingOptions = {
  extension: '.m4a',
  sampleRate: 16000,
  numberOfChannels: 1,
  bitRate: 32000,
  android: { outputFormat: 'mpeg4', audioEncoder: 'aac' },
  ios: { outputFormat: IOSOutputFormat.MPEG4AAC, audioQuality: AudioQuality.LOW },
  web: { mimeType: 'audio/webm', bitsPerSecond: 32000 },
}

/** Whether the microphone is allowed — asked for only when `ask`, which only the switch does. */
export async function microphone(ask: boolean): Promise<'granted' | 'denied' | 'unavailable'> {
  try {
    const now = await getRecordingPermissionsAsync()
    if (now.granted) return 'granted'
    if (!ask) return 'denied'
    return (await requestRecordingPermissionsAsync()).granted ? 'granted' : 'denied'
  } catch {
    return 'unavailable'
  }
}

/** Waits `ms`, or less when told to stop — so turning the switch off stops within a moment, not at the piece's end. */
const pause = (ms: number, stopped: () => boolean): Promise<void> =>
  new Promise((resolve) => {
    const started = Date.now()
    const tick = setInterval(() => {
      if (stopped() || Date.now() - started >= ms) {
        clearInterval(tick)
        resolve()
      }
    }, 200)
  })

async function queuePiece(target: AudioTarget, uri: string, durationMs: number): Promise<void> {
  const seq = takeSeq(target.alertOp)
  const op = await submit({
    endpoint: AUDIO_ENDPOINT,
    // An alert still held on the phone has no id: the piece waits behind it and takes the id from its answer.
    params: target.alertId !== null ? { alert: target.alertId } : { alert: { $from: target.alertOp, key: 'id' } },
    body: { file: { uri, name: `piece-${seq}.m4a`, type: 'audio/mp4' }, seq, duration_ms: Math.round(durationMs) },
    label: 'Live audio to the control room',
    kind: 'audio',
    after: target.alertId !== null ? null : target.alertOp,
  })
  if (target.alertId !== null) void offer(op.id).catch(() => undefined)
}

/**
 * The server said this alert is closed (a piece refused `alert_closed`): nobody is listening, so recording stops even
 * when no screen is open to see the alert close.
 */
const closedOnServer = (target: AudioTarget): boolean =>
  outbox.all().some((op) => {
    if (op.endpoint !== AUDIO_ENDPOINT || op.state !== 'refused' || op.error?.code !== 'alert_closed') return false
    const alert = op.params?.alert
    return alert && typeof alert === 'object' ? alert.$from === target.alertOp : target.alertId !== null && String(alert) === String(target.alertId)
  })

export function LiveAudioHost() {
  const recorder = useAudioRecorder(PIECE)
  const target = useWantedAudio()

  useEffect(() => {
    if (!target) return
    let stopped = false
    const isStopped = () => stopped

    void (async () => {
      try {
        if ((await microphone(false)) !== 'granted') {
          setLiveAudioState('denied')
          return
        }
        await setAudioModeAsync({ allowsRecording: true, allowsBackgroundRecording: true, shouldPlayInBackground: true, playsInSilentMode: true, interruptionMode: 'doNotMix' })
        while (!stopped) {
          if (closedOnServer(target)) {
            wantLiveAudio(null)
            setLiveAudioState('off')
            return
          }
          await recorder.prepareToRecordAsync()
          const began = Date.now()
          recorder.record()
          setLiveAudioState('on')
          await pause(PIECE_MS, isStopped)
          await recorder.stop()
          const uri = recorder.uri
          // A piece cut short by the switch is still sent: it is what was heard.
          if (uri) await queuePiece(target, uri, Date.now() - began)
        }
      } catch {
        if (!stopped) setLiveAudioState('failed')
      } finally {
        try {
          if (recorder.isRecording) await recorder.stop()
        } catch {
          /* already stopped */
        }
        await setAudioModeAsync({ allowsRecording: false, allowsBackgroundRecording: false, shouldPlayInBackground: false }).catch(() => undefined)
      }
    })()

    return () => {
      stopped = true
    }
  }, [target, recorder])

  return null
}
