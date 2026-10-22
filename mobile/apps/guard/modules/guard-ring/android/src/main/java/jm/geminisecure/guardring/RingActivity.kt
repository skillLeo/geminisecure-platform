package jm.geminisecure.guardring

import android.app.KeyguardManager
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.fragment.app.FragmentActivity

/**
 * THE RING SCREEN, over the lock screen — native Views, no React (GUARD_RING_DESIGN §7.4). It makes no sound of its
 * own: the notification is the only noise, and the screen is where the guard answers it.
 *
 *   DRILL band (a drill) · the kind · the estate and lot · "Raised 1 min 12 s ago · 2:11 AM", ticking ·
 *   "Ring 2: every officer of … is being rung" (the escalation) · I'm responding · Can't respond · a status line
 *
 * Several alerts at once (G20): the screen keeps the ringing alerts newest first, shows the newest, and says
 * "1 more alert ringing — tap to see it". Every answer and receipt is for the alert on show.
 *
 * Only this screen shows over the lock screen. The rest of the app opens through `gsguard://alert/<id>` into its own
 * activity, which does not, so payslips and messages never appear over the lock screen.
 */
class RingActivity : FragmentActivity() {
  private val handler = Handler(Looper.getMainLooper())
  private var shownId: String? = null
  private var busy = false
  private val finished = mutableSetOf<String>()

  private lateinit var root: LinearLayout
  private lateinit var band: TextView
  private lateinit var kind: TextView
  private lateinit var place: TextView
  private lateinit var raised: TextView
  private lateinit var escalation: TextView
  private lateinit var more: TextView
  private lateinit var status: TextView
  private lateinit var respond: Button
  private lateinit var cannot: Button
  private lateinit var openApp: Button
  private lateinit var close: Button

  private val screenListener: (String, String, String?) -> Unit = { type, id, detail -> handler.post { onEvent(type, id, detail) } }

  private val tick = object : Runnable {
    override fun run() {
      render()
      handler.postDelayed(this, 1_000L)
    }
  }

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    overLockScreen()
    build()
    RingEvents.addScreen(screenListener)
    arrive(intent)
  }

  override fun onNewIntent(intent: Intent) {
    super.onNewIntent(intent)
    setIntent(intent)
    arrive(intent)
  }

  override fun onResume() {
    super.onResume()
    handler.removeCallbacks(tick)
    handler.post(tick)
  }

  override fun onPause() {
    super.onPause()
    handler.removeCallbacks(tick)
  }

  override fun onDestroy() {
    RingEvents.removeScreen(screenListener)
    handler.removeCallbacksAndMessages(null)
    super.onDestroy()
  }

  /* ---- showing over the lock screen, edge to edge ------------------------------------------------------ */

  private fun overLockScreen() {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
      setShowWhenLocked(true)
      setTurnScreenOn(true)
    } else {
      @Suppress("DEPRECATION")
      window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
    }
    window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    WindowCompat.setDecorFitsSystemWindows(window, false)
  }

  private fun dp(v: Int): Int = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

  private fun line(size: Float, color: Int, bold: Boolean = false): TextView = TextView(this).apply {
    setTextSize(TypedValue.COMPLEX_UNIT_SP, size)
    setTextColor(color)
    if (bold) setTypeface(typeface, Typeface.BOLD)
    setPadding(0, dp(4), 0, dp(4))
  }

  private fun button(label: String, fill: Int, ink: Int): Button = Button(this).apply {
    text = label
    isAllCaps = false
    setTextSize(TypedValue.COMPLEX_UNIT_SP, 20f)
    setTextColor(ink)
    setTypeface(typeface, Typeface.BOLD)
    minHeight = dp(64)
    background = GradientDrawable().apply { cornerRadius = dp(16).toFloat(); setColor(fill) }
    layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(12) }
  }

  private fun build() {
    val white = Color.WHITE
    val dim = Color.parseColor("#AAB6C8")
    root = LinearLayout(this).apply {
      orientation = LinearLayout.VERTICAL
      setBackgroundColor(Color.parseColor("#081422"))
      gravity = Gravity.TOP
    }
    band = line(15f, Color.parseColor("#081422"), bold = true).apply {
      setBackgroundColor(Color.parseColor("#F5B83D"))
      gravity = Gravity.CENTER
      setPadding(dp(12), dp(10), dp(12), dp(10))
      text = RingText.DRILL_BAND
    }
    kind = line(26f, white, bold = true)
    place = line(20f, white)
    raised = line(17f, dim)
    escalation = line(16f, Color.parseColor("#F5B83D"), bold = true)
    more = line(16f, Color.parseColor("#7CC4FF"), bold = true).apply { setOnClickListener { showNext() } }
    status = line(17f, white)
    respond = button("I'm responding", Color.parseColor("#1F9D55"), white).apply { setOnClickListener { respondTapped() } }
    cannot = button("Can't respond", Color.parseColor("#2B3A52"), white).apply { setOnClickListener { cannotTapped() } }
    openApp = button("Open the alert in the Guard App", Color.parseColor("#2B3A52"), white).apply { setOnClickListener { shownId?.let { openAlert(it) } } }
    close = button("Close", Color.parseColor("#2B3A52"), white).apply { setOnClickListener { finish() } }

    val body = LinearLayout(this).apply {
      orientation = LinearLayout.VERTICAL
      setPadding(dp(20), dp(20), dp(20), dp(20))
      addView(kind)
      addView(place)
      addView(raised)
      addView(escalation)
      addView(View(this@RingActivity), LinearLayout.LayoutParams(1, dp(16)))
      addView(status)
      addView(more)
      addView(respond)
      addView(cannot)
      addView(openApp)
      addView(close)
    }
    val scroll = ScrollView(this).apply { isFillViewport = true; addView(body) }
    root.addView(band, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT))
    root.addView(scroll, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1f))
    // G19: the app targets API 36, where edge to edge cannot be opted out of. Nothing sits under the status bar,
    // the camera cut-out or the gesture bar.
    ViewCompat.setOnApplyWindowInsetsListener(root) { v, insets ->
      val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout())
      v.setPadding(bars.left, bars.top, bars.right, bars.bottom)
      insets
    }
    setContentView(root)
  }

  /* ---- which alert is on show ------------------------------------------------------------------------ */

  private fun store() = RingEngine.store(this)

  private fun arrive(intent: Intent?) {
    val id = intent?.getStringExtra(RingLaunch.EXTRA_ID)?.takeIf { RingParser.isSlug(it) }
    val launch = Launch.of(intent?.getStringExtra(RingLaunch.EXTRA_LAUNCH))
    if (id == null) {
      if (store().ringing(RingEngine.now()).isEmpty()) finish() else { shownId = store().ringing(RingEngine.now()).first().id; render() }
      return
    }
    shownId = id
    finished.remove(id)
    status.text = ""
    RingEngine.opened(this, id, launch)
    render()
    if (launch == Launch.ACTION) respondTapped()
  }

  private fun others(): List<LiveRing> = store().ringing(RingEngine.now()).filter { it.id != shownId && it.id !in finished }

  private fun showNext() {
    val next = others().firstOrNull() ?: return
    shownId = next.id
    status.text = ""
    busy = false
    RingEngine.opened(this, next.id, Launch.TAP)
    render()
  }

  private fun render() {
    val id = shownId ?: return
    val nowMs = RingEngine.now()
    val r = store().liveOf(id)
    if (r == null) {
      kind.text = RingText.kindLine("")
      place.text = ""
      raised.text = ""
      respond.visibility = View.GONE
      cannot.visibility = View.GONE
      return
    }
    band.visibility = if (r.drill) View.VISIBLE else View.GONE
    band.text = if (r.test) "TEST · nothing has happened" else RingText.DRILL_BAND
    if (r.test) band.visibility = View.VISIBLE
    kind.text = if (r.test) RingText.TEST_TITLE else RingText.kindLine(r.kind)
    place.text = if (r.test) RingText.TEST_SCREEN else RingText.place(r.where, r.estate)
    raised.text = if (r.test) "" else RingText.raisedLine(r.ageNow(nowMs, RingEngine.elapsed(), RingEngine.boot(this)), r.at)
    escalation.visibility = if (r.escalation && !r.test) View.VISIBLE else View.GONE
    escalation.text = RingText.escalationLine(r.round, r.estate)
    val live = r.stateAt(nowMs)
    val open = !r.test && r.ticket != null && (live == LiveState.RINGING || live == LiveState.HELD) && id !in finished
    respond.visibility = if (open) View.VISIBLE else View.GONE
    cannot.visibility = if (open) View.VISIBLE else View.GONE
    respond.isEnabled = !busy
    cannot.isEnabled = !busy
    if (!r.test && r.ticket == null && id !in finished) {
      openApp.visibility = View.VISIBLE
      if (status.text.isNullOrEmpty()) status.text = RingActions.CANNOT_HERE
    }
    val n = others().size
    more.visibility = if (n > 0) View.VISIBLE else View.GONE
    more.text = if (n == 1) "1 more alert ringing — tap to see it" else "$n more alerts ringing — tap to see them"
    if (r.test && nowMs >= r.holdEndsAtMs()) finish()
  }

  private fun onEvent(type: String, id: String, detail: String?) {
    if (id != shownId) {
      render()
      return
    }
    when (type) {
      "stopped" -> {
        finished.add(id)
        busy = false
        // The stop's own words (why it stopped), unless this guard's own answer is already on the screen.
        if (status.text.isNullOrEmpty() || respond.visibility == View.VISIBLE || status.text.toString() == RingActions.SENDING) {
          val r = store().liveOf(id)
          status.text = detail ?: RingText.stop(null, null, r?.kind, r?.where ?: "", r?.estate ?: "", r?.drill ?: false).text
        }
        render()
      }
      else -> render()
    }
  }

  /* ---- the two buttons ---------------------------------------------------------------------------------- */

  private fun lockFacts(): LockFacts {
    val km = getSystemService(KeyguardManager::class.java)
    return LockFacts(locked = km?.isKeyguardLocked ?: false, secure = km?.isDeviceSecure ?: false, biometric = RingReadiness.biometric(this))
  }

  private fun respondTapped() {
    val id = shownId ?: return
    if (busy) return
    val plan = RingActions.before(Action.RESPOND, lockFacts())
    when (plan.screen) {
      Screen.REQUIRE_UNLOCK -> unlockThen(id)
      Screen.REQUIRE_BIOMETRIC -> biometricThen(id)
      Screen.REFUSE_NO_LOCK -> {
        status.text = plan.message
        openApp.visibility = View.VISIBLE
      }
      else -> send(id)
    }
  }

  private fun unlockThen(id: String) {
    val km = getSystemService(KeyguardManager::class.java)
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || km == null) {
      status.text = RingActions.UNLOCK_FIRST
      return
    }
    km.requestDismissKeyguard(this, object : KeyguardManager.KeyguardDismissCallback() {
      override fun onDismissSucceeded() {
        handler.post { if (shownId == id) send(id) }
      }

      override fun onDismissCancelled() {
        handler.post { status.text = RingActions.UNLOCK_FIRST }
      }

      override fun onDismissError() {
        handler.post { status.text = RingActions.UNLOCK_FIRST }
      }
    })
  }

  private fun biometricThen(id: String) {
    val prompt = BiometricPrompt(this, ContextCompat.getMainExecutor(this), object : BiometricPrompt.AuthenticationCallback() {
      override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
        if (shownId == id) send(id)
      }

      override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
        status.text = RingActions.NO_LOCK
        openApp.visibility = View.VISIBLE
      }
    })
    val info = BiometricPrompt.PromptInfo.Builder()
      .setTitle("Confirm it is you")
      .setSubtitle("Before dispatch and the resident are told you are responding")
      .setNegativeButtonText("Cancel")
      .setAllowedAuthenticators(androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_WEAK)
      .build()
    try {
      prompt.authenticate(info)
    } catch (t: Throwable) {
      status.text = RingActions.NO_LOCK
      openApp.visibility = View.VISIBLE
    }
  }

  /** Unlocked (or proved): silence the ring, write the answer down, send it. */
  private fun send(id: String) {
    val answer = RingEngine.recordAnswer(this, id, "responding", null)
    if (answer == null) {
      status.text = RingActions.CANNOT_HERE
      openApp.visibility = View.VISIBLE
      return
    }
    RingEngine.silence(this, id)
    busy = true
    status.text = RingActions.SENDING
    render()
    val app = applicationContext
    Thread({
      val result = RingEngine.sendAnswer(app, answer)
      handler.post { afterRespond(id, answer, result) }
    }, "guard-ring-answer").start()
  }

  private fun afterRespond(id: String, answer: Pending, result: AnswerResult) {
    if (result.status == RingEngine.STALE) {
      applyPlan(id,RingActions.tooLate())
      return
    }
    val plan = RingActions.afterRespond(result)
    applyPlan(id,plan)
    when (plan.screen) {
      Screen.OPEN_ALERT -> {
        openAlert(id)
        handler.postDelayed({ if (others().isEmpty()) finish() else showNext() }, 400L)
      }
      Screen.NOT_SENT_YET -> handler.postDelayed({ retry(id, answer) }, (plan.retrySeconds ?: 10) * 1000L)
      else -> Unit
    }
  }

  private fun retry(id: String, answer: Pending) {
    if (isFinishing || isDestroyed) return
    val still = store().pending().firstOrNull { it.key == answer.key } ?: return
    if (store().isStale(still, RingEngine.elapsed(), RingEngine.boot(this))) {
      store().removePending(still.key)
      applyPlan(id,RingActions.tooLate())
      return
    }
    val app = applicationContext
    Thread({
      val result = RingEngine.sendAnswer(app, still)
      handler.post { afterRespond(id, still, result) }
    }, "guard-ring-answer").start()
  }

  private fun applyPlan(id: String, plan: Plan) {
    busy = plan.screen == Screen.SENDING
    if (plan.clear) {
      RingEngine.clear(this, id)
      finished.add(id)
    } else if (plan.silence) {
      RingEngine.silence(this, id)
    }
    if (plan.message != null && shownId == id) status.text = plan.message
    if (plan.screen == Screen.CANNOT_ANSWER_HERE || plan.screen == Screen.RECORDED_NO_SESSION) openApp.visibility = View.VISIBLE
    render()
  }

  private fun cannotTapped() {
    val id = shownId ?: return
    if (busy) return
    val plan = RingActions.before(Action.CANNOT, lockFacts(), store().declinedRoundBefore(id, RingEngine.now()))
    val scope = plan.scope ?: "round"
    val answer = RingEngine.recordAnswer(this, id, "cannot", scope)
    RingEngine.decline(this, id, scope)
    finished.add(id)
    status.text = plan.message
    render()
    if (answer == null) return
    val app = applicationContext
    Thread({
      val result = RingEngine.sendAnswer(app, answer)
      handler.post {
        val after = RingActions.afterCannot(result, scope)
        if (shownId == id && after.message != null) status.text = after.message
        if (after.screen == Screen.DECLINE_NOT_SENT) RingEngine.flushInBackground(app)
        render()
      }
    }, "guard-ring-answer").start()
  }

  private fun openAlert(id: String) {
    try {
      startActivity(RingNotifier.appAlertIntent(this, id))
    } catch (t: Throwable) {
      status.text = RingActions.CANNOT_HERE
    }
  }
}
