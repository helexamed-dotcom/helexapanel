package ir.helexapanel.core.net

import ir.helexapanel.core.auth.DeviceIdentity
import ir.helexapanel.core.auth.SessionStore
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.FormBody
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

/**
 * Every request the app makes to the panel.
 *
 * The panel has no token API: it authenticates with a session cookie, bound
 * server-side to a fingerprint of the User-Agent and Accept-Language. So this
 * client does three things on every call, and getting any of them wrong logs
 * the student out rather than failing visibly:
 *
 *  - sends the cookies the store holds,
 *  - sends the frozen headers the fingerprint was computed from,
 *  - saves back whatever the server rotates.
 *
 * Nothing here is a security control on its own. Every rule it appears to
 * enforce is enforced again on the server, which is the copy that counts.
 */
class ApiClient(
    private val baseUrl: String,
    private val store: SessionStore,
    private val http: OkHttpClient
) {

    private val json = Json {
        ignoreUnknownKeys = true   // the server may add fields; old builds must not break
        isLenient = true
    }

    /** @return the parsed object, or a typed failure. Never throws for an expected condition. */
    suspend fun get(path: String): ApiResult<JsonObject> = call(request(path).get().build())

    /**
     * Fetches a rendered page as text.
     *
     * Used for exactly one thing: reading the CSRF token out of the login
     * page's meta tag before the first post. The panel has no endpoint that
     * hands the token out on its own, and inventing one would mean changing a
     * backend the website depends on for a problem the app can solve by
     * reading a page it is allowed to read anyway.
     */
    suspend fun getHtml(path: String): ApiResult<String> = io {
        http.newCall(
            request(path)
                // A page, not an API reply: asking for JSON here would get the
                // login form rendered as a redirect instead of as markup.
                .header("Accept", "text/html")
                .get()
                .build()
        ).execute().use { response ->
            harvestCookies(response)

            val body = response.body?.string().orEmpty()

            if (response.isSuccessful && body.isNotBlank()) {
                ApiResult.Success(body)
            } else {
                ApiResult.Failure(ApiError.Server(response.code))
            }
        }
    }

    suspend fun post(path: String, fields: Map<String, String> = emptyMap()): ApiResult<JsonObject> {
        val body = FormBody.Builder().apply {
            // The panel checks this on every state-changing verb. It is read
            // from a header rather than a field so the same token works for
            // form posts and JSON alike.
            fields.forEach { (k, v) -> add(k, v) }
        }.build()

        return call(request(path).post(body).build())
    }

    private fun request(path: String): Request.Builder {
        val builder = Request.Builder()
            .url(resolve(path))
            .header("User-Agent", DeviceIdentity.USER_AGENT)
            .header("Accept-Language", DeviceIdentity.ACCEPT_LANGUAGE)
            // Marks the call as an API call, which is what makes the panel
            // answer refusals as JSON instead of redirecting to a login page.
            .header("X-Requested-With", "XMLHttpRequest")
            .header("Accept", "application/json")

        store.csrfToken?.let { builder.header("X-CSRF-Token", it) }

        val cookies = store.cookies()
        if (cookies.isNotEmpty()) {
            builder.header("Cookie", cookies.entries.joinToString("; ") { "${it.key}=${it.value}" })
        }

        return builder
    }

    /**
     * Turns a path into a URL on the panel, and refuses anything else.
     *
     * Every request this client makes carries a session cookie, so a caller
     * that could steer it at another host could hand that cookie away. The
     * guard has to reject an absolute URL explicitly: simply concatenating it
     * onto the base would keep the host correct by accident, which looks safe
     * and silently turns "http://evil.com/x" into a nonsense path instead of
     * an error anyone would notice.
     */
    private fun resolve(path: String): HttpUrl {
        require(!path.contains("://")) { "expected a path, got an absolute URL: $path" }
        // A protocol-relative reference is a host in disguise.
        require(!path.startsWith("//")) { "expected a path, got a protocol-relative URL: $path" }

        val base = baseUrl.trimEnd('/')
        val url = (base + "/" + path.trimStart('/')).toHttpUrl()

        // Belt and braces: whatever the path was, the result must be the panel.
        check(url.host == base.toHttpUrl().host) { "refusing to send credentials to ${url.host}" }

        return url
    }

    private suspend fun call(request: Request): ApiResult<JsonObject> = io {
        http.newCall(request).execute().use(::interpret)
    }

    /**
     * Runs one blocking exchange off the caller's thread, and lets nothing
     * escape as an exception.
     *
     * Both halves of that matter, and the app shipped without either.
     *
     * OkHttp's `execute()` blocks. Every caller here is a `viewModelScope`
     * coroutine, which on Android runs on `Dispatchers.Main.immediate` — so
     * without this, every request ran on the main thread and Android threw
     * `NetworkOnMainThreadException` at the first one. That is a
     * `RuntimeException`, so the `IOException` handlers below never saw it: it
     * went straight to the default handler and killed the process the moment
     * the login screen asked for a CSRF token.
     *
     * The JVM has no such policy, which is exactly why the suite could not
     * catch this. `checkDispatch()` now asserts the hop instead.
     *
     * The final `Throwable` catch is the second half. A client is allowed to
     * fail a request; it is not allowed to take the app down with it. A
     * cancellation is not a failure and is rethrown, or a screen closing
     * mid-request would be reported to the student as an error.
     */
    private suspend fun <T> io(block: () -> ApiResult<T>): ApiResult<T> =
        withContext(Dispatchers.IO) {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: UnknownHostException) {
                ApiResult.Failure(ApiError.Offline())
            } catch (e: SocketTimeoutException) {
                ApiResult.Failure(ApiError.Timeout())
            } catch (e: IOException) {
                ApiResult.Failure(ApiError.Offline())
            } catch (e: Throwable) {
                ApiResult.Failure(ApiError.Unreadable())
            }
        }

    private fun interpret(response: Response): ApiResult<JsonObject> {
        harvestCookies(response)

        val raw = response.body?.string().orEmpty()
        val payload = parse(raw)

        // The server's own wording is preferred over anything invented here:
        // it knows how many attempts are left and how long a cooldown runs.
        val serverMessage = payload?.get("message")?.jsonPrimitive?.contentOrNullSafe()

        if (response.isSuccessful) {
            return payload?.let { ApiResult.Success(it) }
                ?: ApiResult.Failure(ApiError.Unreadable())
        }

        val error = when (response.code) {
            401 -> ApiError.Unauthorized(serverMessage ?: ApiError.Unauthorized().message)
            403 -> classifyForbidden(payload, serverMessage)
            404 -> ApiError.NotFound(serverMessage ?: ApiError.NotFound().message)
            419 -> ApiError.StaleToken(serverMessage ?: ApiError.StaleToken().message)
            422 -> ApiError.Validation(serverMessage ?: "اطلاعات وارد شده درست نیست.")
            429 -> ApiError.RateLimited(
                message = serverMessage ?: "کمی سریع پیش رفتی. چند لحظه صبر کن.",
                retryAfterSeconds = retryAfter(response, payload)
            )
            in 500..599 -> ApiError.Server(response.code)
            else -> ApiError.Server(response.code)
        }

        return ApiResult.Failure(error)
    }

    /**
     * A 403 from the panel means one of two very different things, and the
     * student can only act on one of them.
     */
    private fun classifyForbidden(payload: JsonObject?, message: String?): ApiError {
        val code = payload?.get("code")?.jsonPrimitive?.contentOrNullSafe()
        val text = message ?: "به این بخش دسترسی نداری."

        return if (code == "SINGLE_DEVICE" || text.contains("دستگاه دیگری")) {
            ApiError.SingleDevice(text)
        } else {
            ApiError.Forbidden(text)
        }
    }

    private fun retryAfter(response: Response, payload: JsonObject?): Int {
        payload?.get("retry_after")?.jsonPrimitive?.contentOrNullSafe()?.toIntOrNull()?.let { return it }
        response.header("Retry-After")?.toIntOrNull()?.let { return it }
        return 60
    }

    private fun parse(raw: String): JsonObject? = try {
        if (raw.isBlank()) null else json.parseToJsonElement(raw) as? JsonObject
    } catch (e: Exception) {
        null
    }

    /**
     * Keeps the cookie jar in step with the server.
     *
     * The panel rotates its session id on sign-in and issues a remember-me
     * handle separately; missing either would sign the student out on the
     * next launch.
     */
    private fun harvestCookies(response: Response) {
        val set = response.headers("Set-Cookie")
        if (set.isEmpty()) return

        val harvested = set.mapNotNull { header ->
            val pair = header.substringBefore(';').split('=', limit = 2)
            if (pair.size == 2) pair[0].trim() to pair[1].trim() else null
        }.toMap()

        if (harvested.isNotEmpty()) store.saveCookies(harvested)
    }
}

/** kotlinx.serialization returns the literal string "null" for a JSON null. */
private fun kotlinx.serialization.json.JsonPrimitive.contentOrNullSafe(): String? =
    if (this is kotlinx.serialization.json.JsonNull) null else content.takeIf { it.isNotBlank() }

sealed class ApiResult<out T> {
    data class Success<T>(val value: T) : ApiResult<T>()
    data class Failure(val error: ApiError) : ApiResult<Nothing>()

    fun successOrNull(): T? = (this as? Success)?.value
    fun errorOrNull(): ApiError? = (this as? Failure)?.error
}
