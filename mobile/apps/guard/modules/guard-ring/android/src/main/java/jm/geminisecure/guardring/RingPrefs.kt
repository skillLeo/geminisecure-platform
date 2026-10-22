package jm.geminisecure.guardring

import android.annotation.SuppressLint
import android.content.Context
import android.content.SharedPreferences

/**
 * The ring store's disk: one private SharedPreferences file. `commit()`, not `apply()`: a receipt or an answer is
 * written BEFORE it is sent (G22), so it must be on disk when the send starts — a process killed a moment later
 * still has it. The values are small JSON strings; nothing in here is a secret beyond the ring tickets, which
 * answer for one alert for 30 minutes and are gone at sign-out.
 */
class RingPrefs(context: Context) : KeyValue {
  private val prefs: SharedPreferences = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

  override fun get(key: String): String? = try {
    prefs.getString(key, null)
  } catch (e: ClassCastException) {
    null
  }

  @SuppressLint("ApplySharedPref")
  override fun put(key: String, value: String?) {
    val edit = prefs.edit()
    if (value == null) edit.remove(key) else edit.putString(key, value)
    edit.commit()
  }

  companion object {
    const val FILE = "jm.geminisecure.guardring"
  }
}
