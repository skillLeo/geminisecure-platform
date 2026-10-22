/**
 * /(auth)/pending — signed in, waiting for the estate to approve the unit claim. No board draws
 * it; it is assembled from the auth boards' parts (the shell and title, board 04's round
 * illustration, the information strip, the buttons) and it is a REAL screen: it shows what was
 * claimed and when, asks the server again every 15 seconds, opens the app by itself the moment
 * the estate approves, and always offers a way forward (check now · claim a different unit · log out).
 */

import { RefreshControl, StyleSheet, View } from 'react-native'
import { Redirect, router } from 'expo-router'
import { ErrorView, Icon, Pressable, ResidentButton, SkeletonLines, Text, c, enterApp, type IconName } from '@gs/core'
import { useAppSelector } from '@/store'
import { AuthNote, AuthShell, AuthTitle } from '../components/AuthShell'
import { invitedByLine, invitedPendingWords, type ClaimView } from '../claim'
import { POLL_MS, usePendingClaim } from '../usePendingClaim'

type Words = { title: string; sub: string; icon: IconName }

/**
 * A CLAIM THAT CAME FROM AN INVITE (household invites, stage 2): the invited address was recognised at sign-in, so
 * there was no form to fill in, and the estate office confirms invited members with one tap. Said as such.
 */
function pendingWords(view: ClaimView | null, estate: string): Words {
  const claim = view && 'claim' in view ? view.claim : null
  if (claim?.invited_by && view?.kind === 'reviewing') return { ...invitedPendingWords(estate), icon: 'invite-note' }
  return words(view, estate)
}

function words(view: ClaimView | null, estate: string): Words {
  switch (view?.kind) {
    case 'document':
      return { title: 'Your estate needs a document', sub: `${estate} is reviewing your claim and has asked you for something before it can decide.`, icon: 'invite-note' }
    case 'rejected':
      return { title: 'Your claim was not accepted', sub: `${estate} looked at your claim and turned it down. You can claim again.`, icon: 'privacy-note' }
    case 'approved':
    case 'active':
      return { title: 'Your claim is approved', sub: 'Opening your account…', icon: 'check' }
    default:
      return { title: 'Your estate is reviewing your claim', sub: `${estate} checks every claim by hand. You don't need to do anything: this screen updates by itself.`, icon: 'invite-note' }
  }
}

export default function PendingScreen() {
  const sessionStatus = useAppSelector((s) => s.session.status)
  const model = usePendingClaim({
    home: () => enterApp('/(tabs)' as never), // the sign-in and claim screens are cleared from the history

    claim: () => router.replace('/(auth)/claim-unit' as never),
    logIn: () => router.replace('/(auth)/log-in' as never),
  })

  if (sessionStatus === 'signedOut' && !model.loggingOut) return <Redirect href={'/(auth)/log-in' as never} />

  const view = model.view
  const claim = view && 'claim' in view ? view.claim : null
  const w = pendingWords(view, model.estateName ?? 'Your estate office')
  const invited = invitedByLine(claim)

  return (
    <AuthShell canGoBack={false} refreshControl={<RefreshControl refreshing={model.refreshing} onRefresh={model.refresh} tintColor={c('navy-600')} colors={[c('navy-600')]} />}>
      {model.status === 'loading' ? (
        <>
          <AuthTitle title="Checking your claim" sub="Asking your estate where it stands…" />
          <SkeletonLines tone="light" lines={4} style={s.loading} />
        </>
      ) : model.status === 'denied' ? (
        <ErrorView tone="light" message={model.errorMessage ?? 'Your estate has withdrawn this account’s access. Contact the estate office.'} />
      ) : model.status === 'error' ? (
        <ErrorView tone="light" message={model.errorMessage ?? 'Something unexpected happened. Please try again.'} onRetry={model.refresh} />
      ) : (
        <>
          <AuthTitle title={w.title} sub={w.sub} />

          <View style={s.illus}>
            <Icon name={w.icon} size={40} color={view?.kind === 'rejected' ? 'amber-700' : 'navy-600'} />
          </View>

          {claim ? (
            <View style={s.card} role="summary" aria-label="What you sent to your estate">
              {[
                { label: 'Invited by', value: invited ?? '' },
                { label: 'Unit claimed', value: claim.submitted_unit },
                { label: 'Name given', value: claim.submitted_name },
                { label: 'Sent', value: model.submittedAt },
                { label: 'Signed in as', value: model.accountHint ?? '' },
              ]
                .filter((row) => row.value !== '')
                .map((row, i, rows) => (
                  <Row key={row.label} label={row.label} value={row.value} last={i === rows.length - 1} />
                ))}
            </View>
          ) : null}

          {view?.kind === 'document' ? (
            <AuthNote
              tone="warn"
              icon="privacy-note"
              text={`Your estate asked for: ${view.document}. Take or send it to the estate office: documents cannot be uploaded in the app. Your claim stays in review in the meantime.`}
              marginTop={16}
            />
          ) : null}

          {view?.kind === 'rejected' ? (
            <AuthNote tone="warn" icon="privacy-note" text={view.reason ? `The estate's reason: ${view.reason}` : 'The estate did not give a reason. The estate office can tell you more.'} marginTop={16} />
          ) : null}

          {model.fromCache || !model.online ? (
            <AuthNote
              tone="warn"
              icon="privacy-note"
              text={model.fromCache ? 'No connection: this is the last answer saved on this phone, and it may be out of date. It updates by itself when you are back online.' : 'No connection. This screen checks again by itself when you are back online.'}
              marginTop={16}
            />
          ) : null}

          {view?.kind === 'rejected' ? (
            <ResidentButton label="Claim a different unit" onPress={() => router.replace('/(auth)/claim-unit' as never)} style={s.action} />
          ) : model.online ? (
            <ResidentButton variant="outline" label="Check now" onPress={model.refresh} loading={model.refreshing} style={s.action} />
          ) : (
            <ResidentButton variant="outline" label="Check now" onPress={model.refresh} disabled disabledReason="No connection. The screen checks again by itself when you are back online." style={s.action} />
          )}

          {view?.kind === 'rejected' ? null : (
            <Text face="Inter-Regular" size={11.5} lh={1.5} color="slate-500" align="center" style={s.small}>
              {`Checks for an answer every ${POLL_MS / 1000} seconds while this screen is open. When your estate approves, the app opens by itself: no need to log in again.`}
            </Text>
          )}
        </>
      )}

      {model.status === 'loading' ? null : (
        <Pressable onPress={model.logOut} disabled={model.loggingOut} role="button" aria-label="Log out" hitSlop={14} style={s.logOut}>
          <Text face="Inter-Regular" size={13} color="slate-600" align="center">{'Your claim stays with your estate. '}<Text face="Inter-Bold" color="amber-700">{model.loggingOut ? 'Logging out…' : 'Log out'}</Text></Text>
        </Pressable>
      )}
    </AuthShell>
  )
}

function Row({ label, value, last }: { label: string; value: string; last?: boolean }) {
  return (
    <View style={[s.row, last ? s.rowLast : null]}>
      <Text face="Inter-SemiBold" size={12} ls={0.1} color="slate-600">{label}</Text>
      <View style={s.value}><Text face="Inter-Medium" size={14.5} color="navy-900" align="right">{value}</Text></View>
    </View>
  )
}

const s = StyleSheet.create({
  loading: { marginTop: 28 },
  // Board 04's round illustration (.key-illus).
  illus: { width: 88, height: 88, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', backgroundColor: c('navy-100'), borderRadius: 44, marginTop: 24, alignSelf: 'center' },
  // The auth boards' input surface, as a read-only summary.
  card: { backgroundColor: c('navy-100'), borderRadius: 14, paddingHorizontal: 14, paddingVertical: 4, marginTop: 22 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, minHeight: 44, borderBottomWidth: 1, borderBottomColor: c('navy-200') },
  rowLast: { borderBottomWidth: 0 },
  value: { flexShrink: 1 },
  action: { marginTop: 22 },
  small: { marginTop: 10 },
  logOut: { paddingTop: 6, marginTop: 22 },
})
