package ir.helexapanel.core.net

/**
 * Everything that can go wrong between a tap and an answer, named.
 *
 * The point of a closed set is that the UI cannot forget a case: each one has
 * a message a student can act on, and none of them is a stack trace, a PHP
 * notice or a raw JSON body. The server's own Persian message is preferred
 * wherever it sent one, because it knows things the client does not — how
 * many attempts are left, how long a cooldown has to run.
 */
sealed class ApiError {

    /** What to put on screen. Always Persian, always actionable. */
    abstract val message: String

    /** No usable connection. The one case where "check your connection" is honest. */
    data class Offline(
        override val message: String = "اینترنت در دسترس نیست. اتصالت را بررسی کن."
    ) : ApiError()

    /** The request went out but nothing came back in time. */
    data class Timeout(
        override val message: String = "سرور پاسخ نداد. چند لحظه دیگر دوباره تلاش کن."
    ) : ApiError()

    /** The session is gone: expired, revoked, or ended from another device. */
    data class Unauthorized(
        override val message: String = "نشست شما تمام شده. دوباره وارد شو."
    ) : ApiError()

    /** Signed in, but not allowed this. */
    data class Forbidden(override val message: String) : ApiError()

    data class NotFound(
        override val message: String = "چیزی که دنبالش بودی پیدا نشد."
    ) : ApiError()

    /** A form was refused. Carries the server's own wording. */
    data class Validation(override val message: String) : ApiError()

    /** Too fast. retryAfterSeconds is the server's number, not a guess. */
    data class RateLimited(
        override val message: String,
        val retryAfterSeconds: Int
    ) : ApiError()

    /**
     * The page's CSRF token is stale — almost always because the session was
     * rotated underneath it. Recoverable by re-fetching the token, so it is
     * not the same thing as being signed out.
     */
    data class StaleToken(
        override val message: String = "اعتبار صفحه تمام شده. دوباره تلاش کن."
    ) : ApiError()

    /**
     * The account is signed in on another device and the panel allows only
     * one. Its own case because the only way forward is a human decision,
     * not a retry.
     */
    data class SingleDevice(override val message: String) : ApiError()

    /** The server broke. Never shows the student why. */
    data class Server(
        val status: Int,
        override val message: String = "خطایی در سرور رخ داد. کمی بعد دوباره تلاش کن."
    ) : ApiError()

    /**
     * A reply that did not parse.
     *
     * Distinct from Server on purpose: this usually means the app was pointed
     * at something that is not the panel — a captive portal, a proxy, a login
     * page from hotel wifi — and retrying will not help until that changes.
     */
    data class Unreadable(
        override val message: String = "پاسخ سرور قابل خواندن نبود. اگر به شبکه عمومی وصلی، آن را بررسی کن."
    ) : ApiError()
}
