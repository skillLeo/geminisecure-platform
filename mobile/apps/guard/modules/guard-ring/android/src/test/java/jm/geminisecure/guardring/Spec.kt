package jm.geminisecure.guardring

import java.io.File
import org.json.JSONArray
import org.json.JSONObject

/**
 * The decision table and the contract fixtures, read from `spec/` beside `android/`. Gradle runs unit tests with the
 * module's `android/` directory as the working directory, and build.gradle also passes the path explicitly.
 */
object Spec {
  fun dir(): File {
    val candidates = listOfNotNull(System.getProperty("guardring.spec"), "../spec", "spec", "../../spec").map { File(it) }
    return candidates.firstOrNull { File(it, "decisions.json").isFile }
      ?: throw IllegalStateException("spec/decisions.json not found from ${File(".").absolutePath}")
  }

  val decisions: JSONObject by lazy { JSONObject(File(dir(), "decisions.json").readText(Charsets.UTF_8)) }

  fun contract(name: String): JSONObject = JSONObject(File(File(dir(), "contract"), name).readText(Charsets.UTF_8))

  fun rows(section: String): List<JSONObject> {
    val a: JSONArray = decisions.getJSONArray(section)
    return (0 until a.length()).map { a.getJSONObject(it) }
  }

  /** A row's message as the FCM data map expo-notifications hands the service: flat keys, and `body` as a JSON string. */
  fun fcmData(row: JSONObject): Map<String, String> {
    val m = row.getJSONObject("message")
    val out = LinkedHashMap<String, String>()
    m.optJSONObject("flat")?.let { flat -> for (k in flat.keys()) out[k] = flat.get(k).toString() }
    if (m.has("flatBase")) {
      val base = decisions.getJSONObject("base").getJSONObject(m.getString("flatBase"))
      for (k in base.keys()) out[k] = base.get(k).toString()
    }
    when {
      m.has("bodyRaw") -> out["body"] = m.getString("bodyRaw")
      m.has("body") -> out["body"] = m.getJSONObject("body").toString()
      m.has("base") -> {
        val body = JSONObject(decisions.getJSONObject("base").getJSONObject(m.getString("base")).toString())
        m.optJSONObject("set")?.let { set -> for (k in set.keys()) body.put(k, set.get(k)) }
        m.optJSONArray("unset")?.let { unset -> for (i in 0 until unset.length()) body.remove(unset.getString(i)) }
        out["body"] = body.toString()
      }
    }
    return out
  }

  fun optBool(o: JSONObject?, key: String, otherwise: Boolean): Boolean = if (o != null && o.has(key)) o.getBoolean(key) else otherwise
}
