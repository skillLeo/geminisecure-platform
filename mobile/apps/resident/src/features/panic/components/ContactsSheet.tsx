/**
 * WHO TO TEXT, CHOSEN ON THE PANIC SCREEN ITSELF (M01).
 *
 * "Text my emergency contacts (5)" fired one sms: link at every number at once. Android's "Open with" chooser then
 * offered WhatsApp, which takes ONE number and silently dropped the others; the count included the same spouse
 * entered four times; and a resident could not text just one of them. The user: "when user says send emergency
 * contacts a message, like 5, then it should be sent to all, or the user be able to select 1 or any of them."
 *
 * So the button opens this sheet, OVER the panic screen and never below the fold:
 *
 *   every person once, all ticked   "Text N selected" opens ONE text in the phone's own Messages app to all of them
 *   Text / Call beside each         one person at a time — also the way through a messaging app that takes one number
 *   Call 119                         in the sheet as well: nothing on this screen may stand between a person and it
 *
 * It sends nothing and claims nothing. The phone's own Messages app sends it, when the resident presses send there,
 * over the mobile network — no data needed — and no word here says anybody was texted.
 */

import { useEffect, useState } from 'react'
import { Modal, ScrollView, StyleSheet, View } from 'react-native'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import { Icon, Pressable, Text, a, c } from '@gs/core'
import { numberKey, WHATSAPP_NOTE, type SmsContact } from '../panicSms'

type Props = {
  visible: boolean
  contacts: readonly SmsContact[]
  emergencyNumber: string
  onClose: () => void
  onText: (chosen: readonly SmsContact[]) => void
  onCall: (contact: SmsContact) => void
  onCallEmergency: () => void
}

export function ContactsSheet({ visible, contacts, emergencyNumber, onClose, onText, onCall, onCallEmergency }: Props) {
  const insets = useSafeAreaInsets()
  // Everybody ticked each time it opens: "text my emergency contacts" means all of them unless the resident says otherwise.
  const [chosen, setChosen] = useState<Set<string>>(() => new Set(contacts.map((x) => numberKey(x.phone))))
  useEffect(() => {
    if (visible) setChosen(new Set(contacts.map((x) => numberKey(x.phone))))
  }, [visible, contacts])

  const toggle = (contact: SmsContact) =>
    setChosen((now) => {
      const next = new Set(now)
      const key = numberKey(contact.phone)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  const selected = contacts.filter((x) => chosen.has(numberKey(x.phone)))
  const count = selected.length

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={s.scrim}>
        <Pressable onPress={onClose} role="button" aria-label="Close, back to the alert" style={s.scrimTap} />
        <View style={[s.sheet, { paddingBottom: Math.max(18, insets.bottom + 12) }]} aria-modal>
          <Text face="Poppins-SemiBold" size={16} color="navy-900" role="heading">{'Text your emergency contacts'}</Text>
          <Text face="Inter-Regular" size={12} lh={1.5} color="navy-800" style={{ marginTop: 4 }}>
            {'A text from your own phone, in your Messages app: you press send there. It does not go to the control room.'}
          </Text>

          <ScrollView style={s.list} contentContainerStyle={{ paddingVertical: 4 }}>
            {contacts.map((contact) => {
              const on = chosen.has(numberKey(contact.phone))
              const detail = [contact.relationship, contact.phone].filter(Boolean).join(' · ')
              return (
                <View key={numberKey(contact.phone)} style={s.row}>
                  <Pressable onPress={() => toggle(contact)} role="checkbox" aria-checked={on} aria-label={`${contact.name}${contact.relationship ? `, ${contact.relationship}` : ''}`} style={s.pick}>
                    <View style={[s.box, on ? s.boxOn : null]}>{on ? <Icon name="check" size={13} color="white" /> : null}</View>
                    <View style={s.shrink}>
                      <Text face="Inter-SemiBold" size={13} color="navy-900" numberOfLines={1}>{contact.name}</Text>
                      <Text face="Inter-Regular" size={11} color="slate-500" numberOfLines={1}>{detail}</Text>
                    </View>
                  </Pressable>
                  <Pressable onPress={() => onText([contact])} role="button" aria-label={`Text ${contact.name} only`} style={s.mini}>
                    <Text face="Inter-Bold" size={12} color="navy-600">{'Text'}</Text>
                  </Pressable>
                  <Pressable onPress={() => onCall(contact)} role="button" aria-label={`Call ${contact.name}`} style={s.mini}>
                    <Text face="Inter-Bold" size={12} color="navy-600">{'Call'}</Text>
                  </Pressable>
                </View>
              )
            })}
          </ScrollView>

          <Text face="Inter-Regular" size={11} lh={1.45} color="slate-500" style={{ marginTop: 8 }}>{WHATSAPP_NOTE}</Text>

          <Pressable
            onPress={() => (count > 0 ? onText(selected) : undefined)}
            disabled={count === 0}
            role="button"
            aria-label={count > 0 ? `Text ${count} selected in Messages` : 'Choose somebody to text first'}
            aria-disabled={count === 0}
            accessibilityHint={count > 0 ? undefined : 'Tick at least one person above'}
            style={[s.primary, { backgroundColor: c(count > 0 ? 'red-700' : 'slate-300') }]}
          >
            <Icon name="send" size={13} color="white" />
            <Text face="Inter-Bold" size={13} color="white">{count > 0 ? `Text ${count} selected` : 'Choose somebody to text'}</Text>
          </Pressable>

          <View style={s.footer}>
            <Pressable onPress={onCallEmergency} role="button" aria-label={`Call the police on ${emergencyNumber}`} style={[s.footBtn, s.call]}>
              <Icon name="call-btn-2" size={14} color="red-700" />
              <Text face="Inter-Bold" size={12.5} color="red-700">{`Call ${emergencyNumber}`}</Text>
            </Pressable>
            <Pressable onPress={onClose} role="button" aria-label="Close" style={s.footBtn}>
              <Text face="Inter-Bold" size={12.5} color="navy-800">{'Close'}</Text>
            </Pressable>
          </View>
        </View>
      </View>
    </Modal>
  )
}

const s = StyleSheet.create({
  scrim: { flex: 1, justifyContent: 'flex-end', backgroundColor: a('navy-900', 0.55) },
  scrimTap: { flex: 1 },
  sheet: { maxHeight: '86%', backgroundColor: c('white'), borderTopLeftRadius: 20, borderTopRightRadius: 20, paddingTop: 18, paddingHorizontal: 18 },
  shrink: { flexShrink: 1 },
  list: { marginTop: 10, flexGrow: 0 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8, borderBottomWidth: 1, borderBottomColor: c('navy-100'), paddingVertical: 8 },
  pick: { flex: 1, minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 10 },
  box: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: c('navy-200'), alignItems: 'center', justifyContent: 'center' },
  boxOn: { backgroundColor: c('navy-600'), borderColor: c('navy-600') },
  mini: { minWidth: 52, height: 36, borderRadius: 10, borderWidth: 1, borderColor: c('navy-200'), alignItems: 'center', justifyContent: 'center', paddingHorizontal: 10 },
  primary: { height: 48, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, borderRadius: 12, marginTop: 12 },
  footer: { flexDirection: 'row', gap: 10, marginTop: 10 },
  footBtn: { flex: 1, height: 44, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 7, borderRadius: 12, borderWidth: 1, borderColor: c('navy-200') },
  call: { borderColor: c('red-700') },
})
