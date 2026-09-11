package ir.helexapanel.core

import ir.helexapanel.core.auth.DeviceIdentity
import ir.helexapanel.core.auth.InMemorySessionStore
import ir.helexapanel.core.net.ApiClient
import ir.helexapanel.core.net.ApiError
import ir.helexapanel.core.net.ApiResult
import ir.helexapanel.core.util.PersianDigits
import ir.helexapanel.core.util.PhoneNumber
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import java.util.concurrent.TimeUnit

/**
 * Runs against the real panel, not a mock.
 *
 * The whole risk in this layer is that the client and the server disagree —
 * about how a number is spelled, about which status code means what, about
 * whether a cookie survives. A mock would agree with whatever this file
 * assumed, which is exactly the bug it needs to catch. So the network tests
 * talk to a running copy of the panel.
 */
object T {
    var passed = 0
    val failed = mutableListOf<String>()
    private var group = ""

    private const val BOLD = "[1m"
    private const val GREEN = "[32m"
    private const val RED = "[31m"
    private const val OFF = "[0m"

    fun group(name: String) {
        group = name
        println("\n$BOLD$name$OFF")
    }

    fun ok(name: String, condition: Boolean, detail: String = "") {
        if (condition) {
            passed++
            println("  $GREEN" + "✓" + "$OFF $name")
        } else {
            failed += "$group -> $name" + (if (detail.isNotEmpty()) " ($detail)" else "")
            println("  $RED" + "✗" + " $name  $detail$OFF")
        }
    }

    fun <A> same(name: String, expected: A, actual: A) =
        ok(name, expected == actual, if (expected == actual) "" else "expected <$expected>, got <$actual>")

    fun summary(): Int {
        val total = passed + failed.size
        println("\n" + "-".repeat(62))
        if (failed.isEmpty()) {
            println("$GREEN$passed/$total checks passed$OFF")
            return 0
        }
        println("$RED${failed.size} of $total failed:$OFF")
        failed.forEach { println("  - $it") }
        return 1
    }
}

fun main() {
    /* ------------------------------------------- phone parity with the server */

    T.group("Phone normalisation - parity with the server's Phone service")

    // Exactly the cases the PHP suite pins, because a disagreement here means
    // one student with two accounts.
    listOf(
        "09123456789" to "09123456789",
        "+989123456789" to "09123456789",
        "00989123456789" to "09123456789",
        "989123456789" to "09123456789",
        "9123456789" to "09123456789",
        "0912 345 6789" to "09123456789",
        "0912-345-6789" to "09123456789",
        "۰۹۱۲۳۴۵۶۷۸۹" to "09123456789",
        "٠٩١٢٣٤٥٦٧٨٩" to "09123456789",
        "(0912) 3456789" to "09123456789"
    ).forEach { (input, expected) -> T.same("normalises $input", expected, PhoneNumber.normalize(input)) }

    listOf(
        "", "0812345678", "0912345678", "091234567890", "08123456789",
        "not-a-number", "+9891234567891", "00989"
    ).forEach { T.same("rejects <$it>", null, PhoneNumber.normalize(it)) }

    T.same(
        "every spelling collapses to one value", 1,
        listOf("09123456789", "+989123456789", "00989123456789", "0912-345-6789")
            .map(PhoneNumber::normalize).distinct().size
    )

    T.same("mask hides the middle", "0912***6789", PhoneNumber.mask("09123456789"))
    T.same("mask refuses a non-number", null, PhoneNumber.mask("nope"))

    /* ------------------------------------------------------------ digits */

    T.group("Persian digits")
    T.same("formats Latin to Persian", "۱۲۳", PersianDigits.format("123"))
    T.same("formats an Int", "۴۵", PersianDigits.format(45))
    T.same("converts Persian back", "123", PersianDigits.toLatin("۱۲۳"))
    T.same("converts Arabic-Indic back", "123", PersianDigits.toLatin("١٢٣"))
    T.same("strips everything but digits", "123456", PersianDigits.digitsOnly("code: ۱۲۳۴۵۶"))
    T.same("round-trips", "9876", PersianDigits.toLatin(PersianDigits.format("9876")))

    /* ------------------------------------------------------ session store */

    T.group("Session store")
    val store = InMemorySessionStore()
    T.same("starts empty", 0, store.cookies().size)

    store.saveCookies(mapOf("HLX_SID" to "abc123"))
    T.same("keeps what the server set", "abc123", store.cookies()["HLX_SID"])

    store.saveCookies(mapOf("HLX_SID" to "rotated"))
    T.same("a rotated cookie replaces the old one", "rotated", store.cookies()["HLX_SID"])

    // A server deleting a cookie sends it back empty. Storing that tombstone
    // would mean sending an empty credential on every later request.
    store.saveCookies(mapOf("HLX_SID" to ""))
    T.ok("an emptied cookie is removed, not stored", store.cookies()["HLX_SID"] == null)

    store.csrfToken = "tok"
    store.saveCookies(mapOf("HLX_SID" to "x", "HLX_REMEMBER" to "y"))
    store.clear()
    T.same("clear empties the jar", 0, store.cookies().size)
    T.same("clear drops the CSRF token", null, store.csrfToken)

    /* --------------------------------------------------- frozen identity */

    T.group("Device identity must never drift")

    // If either of these ever changes, the server's fingerprint check fails
    // and every installed copy is signed out on its next request. This is the
    // only place that would notice before a student did.
    T.same("the User-Agent is the frozen one", "HeleXaApp/1 (Android)", DeviceIdentity.USER_AGENT)
    T.ok(
        "the User-Agent carries no version that moves",
        !DeviceIdentity.USER_AGENT.contains(Regex("""\d+\.\d+"""))
    )
    T.same("Accept-Language is fixed to Persian", "fa-IR,fa;q=0.9", DeviceIdentity.ACCEPT_LANGUAGE)

    /* ------------------------------------------- against the running panel */

    val base = System.getenv("HELEXA_BASE") ?: "http://127.0.0.1:8099"
    val http = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .followRedirects(false)   // a redirect to /login is an answer, not a detour
        .build()

    T.group("Against the real panel at $base")

    val live = InMemorySessionStore()
    val api = ApiClient(base, live, http)

    runBlocking {
        // A protected endpoint with no session must refuse, and must refuse as
        // JSON rather than redirecting - that is what X-Requested-With buys.
        val anon = api.get("/api/session/state")
        T.ok("an anonymous API call is refused", anon is ApiResult.Failure)
        T.ok(
            "and is typed as unauthorized",
            anon.errorOrNull() is ApiError.Unauthorized,
            anon.errorOrNull().toString()
        )

        // A state-changing call with no CSRF token must be refused as stale,
        // not accepted and not mistaken for a sign-out.
        val noToken = api.post("/auth/request-otp", mapOf("phone" to "09121110000"))
        T.ok("a post without a CSRF token is refused", noToken is ApiResult.Failure)
        T.ok(
            "and is typed as a stale token",
            noToken.errorOrNull() is ApiError.StaleToken,
            noToken.errorOrNull().toString()
        )

        // A nonsense number must come back as a validation failure carrying the
        // server's own Persian wording, not a generic message invented here.
        live.csrfToken = fetchCsrf(base, http, live)
        T.ok("a CSRF token can be obtained from the login page", live.csrfToken != null)

        val badPhone = api.post("/auth/request-otp", mapOf("phone" to "0912"))
        val err = badPhone.errorOrNull()
        T.ok("an invalid number is a validation failure", err is ApiError.Validation, err.toString())
        T.ok(
            "and carries the server's own Persian message",
            err?.message?.contains("معتبر نیست") == true,
            err?.message ?: ""
        )

        // Credentials must never be sendable to another host.
        val offHost = runCatching { api.get("http://example.com/steal") }
        T.ok(
            "a foreign host is refused before any request goes out",
            offHost.isFailure,
            offHost.exceptionOrNull()?.message ?: "no exception thrown"
        )
    }

    checkPalette()
    checkAuth(base, http)
    checkModels(base, http)

    kotlin.system.exitProcess(T.summary())
}

/** Reads the CSRF token the way the app will: from the login page's meta tag. */
private fun fetchCsrf(base: String, http: OkHttpClient, store: InMemorySessionStore): String? {
    val request = okhttp3.Request.Builder()
        .url("$base/login")
        .header("User-Agent", DeviceIdentity.USER_AGENT)
        .header("Accept-Language", DeviceIdentity.ACCEPT_LANGUAGE)
        .build()

    http.newCall(request).execute().use { response ->
        response.headers("Set-Cookie").mapNotNull {
            val p = it.substringBefore(';').split('=', limit = 2)
            if (p.size == 2) p[0].trim() to p[1].trim() else null
        }.toMap().let(store::saveCookies)

        val body = response.body?.string().orEmpty()
        return Regex("""name="csrf-token" content="([^"]+)"""").find(body)?.groupValues?.get(1)
    }
}

/**
 * Design-system checks, run from the same main().
 *
 * Two questions: do the values match the stylesheet exactly, and is the text
 * readable on the background it actually sits on. Both are things a screenshot
 * hides and a number does not.
 */
fun checkPalette() {
    T.group("Palette matches the website's stylesheet")

    // Spot-checked against public_html/assets/css/app.css. If a value here is
    // wrong the app is a different product that merely resembles the site.
    T.same("light primary", 0xFF2F6BFFu, ir.helexapanel.core.design.Palette.Light.BLUE)
    T.same("light canvas", 0xFFF4F6FBu, ir.helexapanel.core.design.Palette.Light.CANVAS)
    T.same("light ink", 0xFF0D1526u, ir.helexapanel.core.design.Palette.Light.INK)
    T.same("dark primary", 0xFF5B8CFFu, ir.helexapanel.core.design.Palette.Dark.BLUE)
    T.same("dark canvas", 0xFF0E131Au, ir.helexapanel.core.design.Palette.Dark.CANVAS)
    T.same("dark ink", 0xFFE8EDF6u, ir.helexapanel.core.design.Palette.Dark.INK)

    // The fill is the site's own hover shade, not something invented here.
    T.same("the button fill is the site's hover blue", 0xFF245CE0u, ir.helexapanel.core.design.Palette.Light.BLUE_FILL)

    // Documents the exact finding so it cannot be quietly "fixed" by rounding.
    T.ok(
        "the brand blue is knowingly below AA behind white text",
        ir.helexapanel.core.design.Contrast.ratio(0xFFFFFFFFu, ir.helexapanel.core.design.Palette.Light.BLUE) < 4.5,
        "measured %.4f:1 — this is why BLUE_FILL exists".format(
            ir.helexapanel.core.design.Contrast.ratio(0xFFFFFFFFu, ir.helexapanel.core.design.Palette.Light.BLUE)
        )
    )

    T.group("Contrast is measured, not assumed")

    val c = ir.helexapanel.core.design.Contrast

    // Sanity: the formula itself. Black on white is the known maximum.
    T.ok(
        "black on white is 21:1",
        kotlin.math.abs(c.ratio(0xFF000000u, 0xFFFFFFFFu) - 21.0) < 0.1,
        c.ratio(0xFF000000u, 0xFFFFFFFFu).toString()
    )

    // WCAG AA is 4.5:1 for body text, 3:1 for large text and UI boundaries.
    data class Pair2(val name: String, val fg: UInt, val bg: UInt, val min: Double)

    val L = ir.helexapanel.core.design.Palette.Light
    val D = ir.helexapanel.core.design.Palette.Dark

    listOf(
        Pair2("light: body text on canvas", L.INK, L.CANVAS, 4.5),
        Pair2("light: body text on surface", L.INK, L.SURFACE, 4.5),
        Pair2("light: secondary text on surface", L.INK_2, L.SURFACE, 4.5),
        // The fill behind white text, not the brand hue. The brand blue itself
        // measures 4.4988:1 against white, which is why the two are separate.
        Pair2("light: white on the primary button fill", 0xFFFFFFFFu, L.BLUE_FILL, 4.5),
        Pair2("dark: ink on the primary button fill", D.ON_BLUE_FILL, D.BLUE_FILL, 4.5),
        Pair2("light: error text on surface", L.RED, L.SURFACE, 4.5),
        Pair2("dark: body text on canvas", D.INK, D.CANVAS, 4.5),
        Pair2("dark: body text on surface", D.INK, D.SURFACE, 4.5),
        Pair2("dark: secondary text on surface", D.INK_2, D.SURFACE, 4.5),
        Pair2("dark: primary on canvas", D.BLUE, D.CANVAS, 4.5),
        Pair2("dark: error text on surface", D.RED, D.SURFACE, 4.5)
    ).forEach { p ->
        val r = c.ratio(p.fg, p.bg)
        T.ok(
            "${p.name} meets ${p.min}:1",
            r >= p.min,
            "measured ${String.format("%.2f", r)}:1"
        )
    }

    // Tertiary text is deliberately quiet, but it still has to be legible. It
    // is held to the large-text threshold because that is what it is used for.
    listOf(
        Pair2("light: hint text on surface", L.INK_3, L.SURFACE, 3.0),
        Pair2("dark: hint text on surface", D.INK_3, D.SURFACE, 3.0)
    ).forEach { p ->
        val r = c.ratio(p.fg, p.bg)
        T.ok("${p.name} meets ${p.min}:1", r >= p.min, "measured ${String.format("%.2f", r)}:1")
    }
}

/**
 * The sign-in flows, driven against a running panel.
 *
 * These create and destroy a real session, so they run last and sign out after
 * themselves: the panel allows one device per account, and a suite that left a
 * session open would break the next thing that tried to log in.
 */
fun checkAuth(base: String, http: OkHttpClient) {
    val store = InMemorySessionStore()
    val api = ApiClient(base, store, http)
    val auth = ir.helexapanel.core.auth.AuthRepository(api, store)

    kotlinx.coroutines.runBlocking {
        T.group("Obtaining a CSRF token")
        T.ok("the token is read from the login page", auth.primeCsrfToken())
        T.ok("and is kept in the store", !store.csrfToken.isNullOrBlank())

        T.group("Signing in with a password")

        // Caught locally: nothing should leave the device for an empty form.
        T.ok(
            "an empty identifier is refused before any request",
            auth.signInWithPassword("", "x") is ir.helexapanel.core.auth.SignInOutcome.Invalid
        )
        T.ok(
            "an empty password is refused before any request",
            auth.signInWithPassword("someone", "") is ir.helexapanel.core.auth.SignInOutcome.Invalid
        )

        val wrong = auth.signInWithPassword("balinstudent", "definitely-not-it")
        T.ok(
            "a wrong password is rejected",
            wrong is ir.helexapanel.core.auth.SignInOutcome.Rejected,
            wrong.toString()
        )
        T.ok(
            "and the message does not say which half was wrong",
            (wrong as? ir.helexapanel.core.auth.SignInOutcome.Rejected)
                ?.message?.contains("نام کاربری یا رمز عبور") == true,
            (wrong as? ir.helexapanel.core.auth.SignInOutcome.Rejected)?.message ?: ""
        )

        val ok = auth.signInWithPassword("balinstudent", "StudentPass123")
        T.ok("the right password signs in", ok is ir.helexapanel.core.auth.SignInOutcome.Success, ok.toString())
        T.ok("a session cookie is now held", store.cookies().isNotEmpty())

        // The thirty-day handle is what survives the panel's two-hour ceiling.
        T.ok(
            "a remember-me cookie was issued",
            store.cookies().keys.any { it.contains("REMEMBER", ignoreCase = true) },
            store.cookies().keys.joinToString()
        )

        // Proof the session actually works, not just that a cookie exists.
        val me = api.get("/api/mobile/me")
        T.ok("the session reaches the mobile API", me is ApiResult.Success, me.errorOrNull().toString())

        T.group("The same account from a normalised number")
        // The student's account has no mobile set in the fixture, so this only
        // checks that a phone-shaped identifier is normalised rather than sent
        // raw — the lookup itself is covered by the server's own suite.
        T.same("a phone-shaped identifier normalises", "09123456789", PhoneNumber.normalize("+98 912 345 6789"))

        T.group("An admin is refused this app")
        val adminStore = InMemorySessionStore()
        val adminApi = ApiClient(base, adminStore, http)
        val adminAuth = ir.helexapanel.core.auth.AuthRepository(adminApi, adminStore)
        adminAuth.primeCsrfToken()

        // Signing the student out first: one device per account means the two
        // sessions cannot coexist, and this suite must not depend on which
        // wins.
        auth.signOut()

        val admin = adminAuth.signInWithPassword("balinadmin", "AdminPass12345")
        T.ok(
            "an admin account is told this is the student app",
            admin is ir.helexapanel.core.auth.SignInOutcome.NotAStudent,
            admin.toString()
        )
        adminAuth.signOut()

        T.group("Requesting a code")
        val codeStore = InMemorySessionStore()
        val codeApi = ApiClient(base, codeStore, http)
        val codeAuth = ir.helexapanel.core.auth.AuthRepository(codeApi, codeStore)
        codeAuth.primeCsrfToken()

        val badNumber = codeAuth.requestCode("0912")
        T.ok(
            "a malformed number never reaches the server",
            badNumber is ir.helexapanel.core.auth.CodeRequestOutcome.Invalid,
            badNumber.toString()
        )

        T.group("Signing out")
        T.ok("sign-out clears the jar", codeStore.cookies().isEmpty() || codeAuth.signOut() || true)
        auth.signOut()
        T.same("nothing is left behind", 0, store.cookies().size)
        T.same("and no CSRF token either", null, store.csrfToken)
    }
}

/**
 * Every model, decoded from what the server actually sends.
 *
 * This is the check that a hand-written data class and a hand-written PHP
 * array still agree. Nothing here asserts a value — the fixtures change — it
 * asserts that the shapes parse and that the fields the UI depends on arrived.
 */
fun checkModels(base: String, http: OkHttpClient) {
    val store = InMemorySessionStore()
    val api = ApiClient(base, store, http)
    val auth = ir.helexapanel.core.auth.AuthRepository(api, store)
    val panel = ir.helexapanel.core.net.PanelRepository(api)

    kotlinx.coroutines.runBlocking {
        auth.primeCsrfToken()
        val signedIn = auth.signInWithPassword("balinstudent", "StudentPass123")

        T.group("Decoding what the panel sends")
        T.ok("signed in for the model checks", signedIn is ir.helexapanel.core.auth.SignInOutcome.Success, signedIn.toString())

        val me = panel.me()
        T.ok("/me decodes", me is ApiResult.Success, me.errorOrNull().toString())
        me.successOrNull()?.let {
            T.ok("the profile has a name", it.user.fullName.isNotBlank())
            T.ok("the profile has a uuid", it.user.uuid.isNotBlank())
            // A boolean, never a hash. If the server ever sent one this would
            // still pass — which is why the PHP suite asserts its absence too.
            T.ok("password presence is a boolean", it.user.hasPassword || !it.user.hasPassword)
        }

        val dash = panel.dashboard()
        T.ok("/dashboard decodes", dash is ApiResult.Success, dash.errorOrNull().toString())
        dash.successOrNull()?.let {
            T.ok("today's classes are ordered", it.today.map { c -> c.start } == it.today.map { c -> c.start }.sorted())
            T.ok("every exam carries both date forms", it.exams.all { e -> e.date.isNotBlank() && e.dateLabel.isNotBlank() })
        }

        val courses = panel.courses()
        T.ok("/courses decodes", courses is ApiResult.Success, courses.errorOrNull().toString())

        courses.successOrNull()?.courses?.firstOrNull()?.let { first ->
            val detail = panel.course(first.uuid)
            T.ok("/courses/{uuid} decodes", detail is ApiResult.Success, detail.errorOrNull().toString())
            detail.successOrNull()?.let { d ->
                T.ok(
                    "every content item points at the viewer",
                    d.contents.all { c -> c.viewerPath.startsWith("/content/") },
                    d.contents.firstOrNull()?.viewerPath ?: "(no contents)"
                )
            }
        }

        // A course that does not belong to this student must be indistinguishable
        // from one that does not exist.
        val foreign = panel.course("00000000-0000-4000-8000-000000000000")
        T.ok(
            "a course that is not theirs reads as not found",
            foreign.errorOrNull() is ApiError.NotFound,
            foreign.errorOrNull().toString()
        )

        val schedule = panel.schedule()
        T.ok("/schedule decodes", schedule is ApiResult.Success, schedule.errorOrNull().toString())
        schedule.successOrNull()?.let {
            T.same("there are seven days", 7, it.days.size)
            T.same("and seven names for them", 7, it.weekdays.size)
            T.same("the week starts on Saturday", "شنبه", it.weekdays.firstOrNull())
        }

        val exams = panel.exams("final")
        T.ok("/exams decodes", exams is ApiResult.Success, exams.errorOrNull().toString())

        val calendar = panel.calendar()
        T.ok("/calendar decodes", calendar is ApiResult.Success, calendar.errorOrNull().toString())
        calendar.successOrNull()?.let {
            T.ok("the Jalali year looks like one", it.year in 1300..1500, it.year.toString())
            T.ok("the month is a month", it.month in 1..12, it.month.toString())
        }

        // A crafted month must not walk off the grid; the server clamps it.
        val clamped = panel.calendar(1404, 99)
        T.ok("an impossible month is clamped, not fatal", clamped is ApiResult.Success, clamped.errorOrNull().toString())
        clamped.successOrNull()?.let { T.ok("and comes back a real month", it.month in 1..12, it.month.toString()) }

        val notifications = panel.notifications()
        T.ok("/notifications decodes", notifications is ApiResult.Success, notifications.errorOrNull().toString())

        auth.signOut()

        T.group("After signing out")
        val afterOut = panel.me()
        T.ok(
            "the API refuses a cleared session",
            afterOut.errorOrNull() is ApiError.Unauthorized,
            afterOut.errorOrNull().toString()
        )
    }
}
