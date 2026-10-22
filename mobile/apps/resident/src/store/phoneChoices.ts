/**
 * WHAT THIS PHONE WAS TOLD, KEPT THROUGH A SIGN-OUT NOBODY CHOSE (M47 §3).
 *
 * The thirty-minute window closing, or a revocation, cleared every key but the queue and three named ones — so the
 * resident's own choices on this phone went with the session: the text size, reduce motion and the app lock, which
 * notifications they want, data-light, the estate the Log in dropdown opens on, and that the permissions were explained
 * here. None of it names anybody or anything they did; all of it is the person's to keep. A sign-out they CHOSE still
 * clears it.
 *
 * Full storage keys (`jsonStore`'s `version:name`), written out here so the store does not import the screens'
 * modules; `phoneChoices.test.ts` holds each one to the module that writes it.
 */
export const PHONE_CHOICES = ['v1:settings.prefs', 'v1:notifications.prefs', 'v1:prefs.dataLight', 'v1:pref:last_estate', 'v1:pref:permissions_asked'] as const
