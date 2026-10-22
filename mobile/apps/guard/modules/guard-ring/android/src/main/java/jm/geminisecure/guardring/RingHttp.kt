package jm.geminisecure.guardring

import android.content.Context
import android.content.pm.ApplicationInfo
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import org.json.JSONObject

/**
 * The one native HTTP client, for `ring.answer` and `ring.receipt` only. It exists because JS is not running when a
 * killed phone rings, and the guard must be able to answer from the lock screen. The credential is the ring ticket
 * in the body — never the Guard App's bearer token, which the idle window may have closed.
 *
 * HTTPS only, except in a debuggable build (a laptop server). 8 s timeouts. A body that is not the API's contract
 * shape (`{"error": {"code", "message"}}`) is never shown: `message` is null then, and the screen says something plain.
 * Nothing here logs a ticket, a body or an answer.
 */
object RingHttp {
  data class Response(val status: Int, val code: String?, val message: String?, val json: JSONObject?)

  const val TIMEOUT_MS = 8_000
  private const val MAX_BODY = 64 * 1024

  fun deviceTime(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSSXXX", Locale.US).format(Date())

  private fun debuggable(context: Context): Boolean = (context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0

  fun post(context: Context, base: String?, path: String, body: JSONObject, timeoutMs: Int = TIMEOUT_MS): Response {
    if (base.isNullOrBlank()) return Response(-1, "not_configured", null, null)
    val url = base.trimEnd('/') + "/" + path.trimStart('/')
    if (!url.startsWith("https://") && !(debuggable(context) && url.startsWith("http://"))) return Response(-1, "insecure", null, null)
    var conn: HttpURLConnection? = null
    return try {
      conn = (URL(url).openConnection() as HttpURLConnection).apply {
        requestMethod = "POST"
        connectTimeout = timeoutMs
        readTimeout = timeoutMs
        doOutput = true
        useCaches = false
        instanceFollowRedirects = false
        setRequestProperty("Content-Type", "application/json")
        setRequestProperty("Accept", "application/json")
        setRequestProperty("X-Device-Time", deviceTime())
      }
      conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
      val status = conn.responseCode
      val stream = try {
        if (status >= 400) conn.errorStream else conn.inputStream
      } catch (e: Exception) {
        null
      }
      val text = stream?.use { s ->
        val bytes = s.readBytes()
        String(if (bytes.size > MAX_BODY) bytes.copyOf(MAX_BODY) else bytes, Charsets.UTF_8)
      }
      val json = try {
        if (text.isNullOrBlank()) null else JSONObject(text)
      } catch (e: Exception) {
        null
      }
      val error = json?.optJSONObject("error")
      val code = error?.opt("code") as? String
      val message = error?.opt("message") as? String
      Response(status, code, if (code != null) message else null, json)
    } catch (t: Throwable) {
      Response(-1, null, null, null)
    } finally {
      try {
        conn?.disconnect()
      } catch (t: Throwable) {
        // nothing
      }
    }
  }
}
