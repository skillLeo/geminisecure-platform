/**
 * "Is it fixed?" — the household's answer once the estate office has marked a request or a report completed (the
 * client's report R8, 26 Sep: a resident could not say whether it was). No board draws it — boards 26 and 27 show the
 * work still in progress — but it is how the timeline's last stage, "Closed by you" (the board's "Verified by you", M98), is ever reached. So it is built
 * from the boards' own parts (the card, a field, the two buttons side by side with the secondary on the left) and says
 * no more than it must.
 *
 * "Yes, it's fixed" confirms it (`tickets.confirm_fixed`). "Still a problem" asks what is still wrong and sends it back
 * to the office's queue (`tickets.still_a_problem`). Both are saved on the phone first and sent like any request; while
 * one waits the card says so, and a refusal is said in the server's words.
 */

import { useState } from 'react'
import { StyleSheet, View } from 'react-native'
import { Text } from '@gs/core'
import { ButtonRow, Card, Field, PrimaryButton, SecondaryButton } from '@/design/parts'
import { font, ink, role, space } from '@/design/tokens'
import type { FixedModel } from '../useTickets'

/** The server's limits on what is still wrong (tickets.still_a_problem: 3–500). */
const REASON_MIN = 3
const REASON_MAX = 500

export function FixedAnswer({ fixed, what }: { fixed: FixedModel; what: 'request' | 'report' }) {
  const [writing, setWriting] = useState(false)
  const [reason, setReason] = useState('')
  const [tried, setTried] = useState(false)
  const tooShort = reason.trim().length < REASON_MIN

  const send = () => {
    if (tooShort) {
      setTried(true)
      return
    }
    fixed.reopen(reason)
    setWriting(false)
    setReason('')
    setTried(false)
  }
  const cancel = () => {
    setWriting(false)
    setTried(false)
  }

  return (
    <Card>
      <View role="group" aria-label={`Is this ${what} fixed?`}>
        <Text {...role.cardTitle} color={ink.heading} role="heading">{fixed.confirmed ? 'You said it is fixed' : 'Is it fixed?'}</Text>

        {fixed.sending ? (
          <View style={s.note}>
            <Text {...font.inter500_11_5} color={ink.secondary} role="status">
              {fixed.sending === 'confirm' ? 'Saved on this phone: telling the estate office it is fixed.' : 'Saved on this phone: sending it back to the estate office.'}
            </Text>
          </View>
        ) : null}
        {fixed.refusal ? (
          <View style={s.note}><Text {...font.inter500_11_5} color={ink.onGoldTint} role="alert">{fixed.refusal}</Text></View>
        ) : null}

        {writing && !fixed.sending ? (
          <View style={s.reason}>
            <Field
              label="What is still wrong?"
              icon="p-edit"
              value={reason}
              onChangeText={setReason}
              error={tried && tooShort ? 'Say in a few words what is still wrong.' : null}
              maxLength={REASON_MAX}
              autoFocus
              autoCapitalize="sentences"
              returnKeyType="send"
              onSubmitEditing={send}
              last
            />
            <ButtonRow style={s.buttons}>
              <SecondaryButton label="Cancel" onPress={cancel} />
              <PrimaryButton label="Send it back" name={`Send this ${what} back to the estate office`} onPress={send} />
            </ButtonRow>
          </View>
        ) : null}

        {!writing && !fixed.sending && (fixed.canReopen || fixed.canConfirm) ? (
          <ButtonRow style={s.buttons}>
            {fixed.canReopen ? <SecondaryButton label="Still a problem" onPress={() => setWriting(true)} /> : null}
            {fixed.canConfirm ? <PrimaryButton label="Yes, it's fixed" onPress={fixed.confirm} /> : null}
          </ButtonRow>
        ) : null}
      </View>
    </Card>
  )
}

const s = StyleSheet.create({
  note: { marginTop: space[6] },
  reason: { marginTop: space[12] },
  buttons: { marginTop: space[12] },
})
