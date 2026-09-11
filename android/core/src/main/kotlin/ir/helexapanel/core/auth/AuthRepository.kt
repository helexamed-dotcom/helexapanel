package ir.helexapanel.core.auth

import ir.helexapanel.core.net.ApiClient
import ir.helexapanel.core.net.ApiError
import ir.helexapanel.core.net.ApiResult
import ir.helexapanel.core.util.PersianDigits
import ir.helexapanel.core.util.PhoneNumber
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonPrimitive

/**
 * Signing in, both ways the panel allows.
 *
 * All of this is deliberately free of Android types so it can be exercised
 * against the real server from a plain JVM. The screens above it hold no rules
 * of their own: what counts as a valid number, when a code may be resent, how
 * many digits it has — every one of those answers comes from here or from the
 * server, never from a layout.
 */
class AuthRepository(
    private val api: ApiClient,
    private val store: SessionStore
) {

    /**
     * The panel rotates its CSRF token on sign-in and expects it echoed on
     * every state-changing request. It lives in a meta tag on any rendered
     * page, so the cheapest honest way to obtain one is to load the login
     * page — which the app does once, before its first post.
     */
    suspend fun primeCsrfToken(): Boolean {
        val result = api.getHtml("/login")
        val html = result.successOrNull() ?: return false

        val token = CSRF_META.find(html)?.groupValues?.getOrNull(1)
        if (token.isNullOrBlank()) return false

        store.csrfToken = token
        return true
    }

    /**
     * Username-or-mobile plus password.
     *
     * The identifier is normalised here when it looks like a phone number, so
     * "+98 912…" and "۰۹۱۲…" reach the same account as "0912…". Anything that
     * is not a number is passed through untouched and matched as a username,
     * exactly as the web form does.
     */
    suspend fun signInWithPassword(identifier: String, password: String): SignInOutcome {
        if (identifier.isBlank()) return SignInOutcome.Invalid("شماره موبایل یا نام کاربری را وارد کن.")
        if (password.isEmpty()) return SignInOutcome.Invalid("رمز عبور را وارد کن.")

        val lookup = PhoneNumber.normalize(identifier) ?: identifier.trim()

        val result = api.post(
            "/login",
            mapOf(
                "identifier" to lookup,
                "password" to password,
                // The app is the student's own device, and they chose to stay
                // signed in for thirty days. This is the handle that survives
                // the two-hour session ceiling.
                "remember" to "1"
            )
        )

        return interpretSignIn(result)
    }

    /** Asks the server to text a code. */
    suspend fun requestCode(rawPhone: String): CodeRequestOutcome {
        val phone = PhoneNumber.normalize(rawPhone)
            ?: return CodeRequestOutcome.Invalid("شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹")

        return when (val result = api.post("/auth/request-otp", mapOf("phone" to phone))) {
            is ApiResult.Success -> CodeRequestOutcome.Sent(
                phone = phone,
                // The server reports both, because neither is a constant: the
                // cooldown is an admin setting and the code length depends on
                // which SMS provider minted it.
                resendAfterSeconds = result.value.int("retry_after") ?: 60,
                codeLength = result.value.int("code_length")?.takeIf { it in 4..12 } ?: 6,
                message = result.value.string("message") ?: "کد تأیید پیامک شد."
            )

            is ApiResult.Failure -> when (val e = result.error) {
                is ApiError.RateLimited -> CodeRequestOutcome.Cooldown(e.message, e.retryAfterSeconds)
                else -> CodeRequestOutcome.Failed(e)
            }
        }
    }

    /**
     * Submits a code and, if it is right, signs in.
     *
     * The panel creates the account here when the number has never been seen
     * and self-registration is open, so this one call is both sign-in and
     * sign-up — which is why there is no separate registration screen.
     */
    suspend fun submitCode(phone: String, rawCode: String, expectedLength: Int): SignInOutcome {
        val code = PersianDigits.digitsOnly(rawCode)

        if (code.length != expectedLength) {
            return SignInOutcome.Invalid("کد باید ${PersianDigits.format(expectedLength)} رقم باشد.")
        }

        val result = api.post(
            "/auth/verify-otp",
            mapOf("phone" to phone, "code" to code, "remember" to "1")
        )

        return interpretSignIn(result)
    }

    /**
     * Ends the session on the server, then locally.
     *
     * The order matters and the local half is unconditional: if the network
     * call fails, the credentials still have to leave the device, or "sign
     * out" would mean "sign out unless the wifi is bad".
     */
    suspend fun signOut(): Boolean {
        val result = api.post("/logout")
        store.clear()
        return result is ApiResult.Success
    }

    private fun interpretSignIn(result: ApiResult<JsonObject>): SignInOutcome = when (result) {
        is ApiResult.Success -> {
            val redirect = result.value.string("redirect")

            when {
                // The panel sends an admin to /admin. This app is the student
                // client; letting an admin in would show them a shell with
                // nothing behind it, so it says so plainly instead.
                redirect != null && redirect.startsWith("/admin") ->
                    SignInOutcome.NotAStudent

                redirect == "/account/password" ->
                    SignInOutcome.MustChangePassword

                else -> SignInOutcome.Success
            }
        }

        is ApiResult.Failure -> when (val e = result.error) {
            is ApiError.SingleDevice -> SignInOutcome.OtherDevice(e.message)
            is ApiError.Validation -> SignInOutcome.Rejected(e.message)
            is ApiError.Unauthorized -> SignInOutcome.Rejected(e.message)
            is ApiError.Forbidden -> SignInOutcome.Rejected(e.message)
            is ApiError.RateLimited -> SignInOutcome.Rejected(e.message)
            else -> SignInOutcome.Failed(e)
        }
    }

    private companion object {
        val CSRF_META = Regex("""name="csrf-token"\s+content="([^"]+)"""")
    }
}

/* ------------------------------------------------------------- outcomes */

/**
 * What can come back from an attempt to sign in.
 *
 * A closed set so no screen can forget a case — in particular OtherDevice,
 * which is not a retryable failure but a decision the student has to make.
 */
sealed class SignInOutcome {
    /** In. The session cookie is already in the store. */
    object Success : SignInOutcome()

    /** The credentials were right but a temporary password must be replaced first. */
    object MustChangePassword : SignInOutcome()

    /** An admin account. Correct credentials, wrong app. */
    object NotAStudent : SignInOutcome()

    /** Wrong credentials, locked account, or a refusal the server worded itself. */
    data class Rejected(val message: String) : SignInOutcome()

    /** The account is open on another device and the panel allows only one. */
    data class OtherDevice(val message: String) : SignInOutcome()

    /** Something the student did not cause: no network, a broken server. */
    data class Failed(val error: ApiError) : SignInOutcome()

    /** Caught before anything left the device. */
    data class Invalid(val message: String) : SignInOutcome()
}

sealed class CodeRequestOutcome {
    data class Sent(
        val phone: String,
        val resendAfterSeconds: Int,
        val codeLength: Int,
        val message: String
    ) : CodeRequestOutcome()

    /** Asked again too soon. Not an error — a wait, with a number attached. */
    data class Cooldown(val message: String, val secondsRemaining: Int) : CodeRequestOutcome()

    data class Failed(val error: ApiError) : CodeRequestOutcome()
    data class Invalid(val message: String) : CodeRequestOutcome()
}

/* --------------------------------------------------------- json helpers */

internal fun JsonObject.string(key: String): String? =
    (this[key] as? JsonPrimitive)?.takeIf { it.isString || it.content != "null" }
        ?.content?.takeIf { it.isNotBlank() && it != "null" }

internal fun JsonObject.int(key: String): Int? =
    (this[key] as? JsonPrimitive)?.content?.toIntOrNull()
