package ir.helexapanel.core.auth

/**
 * Where the two things that keep a student signed in are kept.
 *
 * The panel's sign-in state is two cookies: a session id that the server
 * rotates, and a remember-me handle that survives the session expiring. Both
 * are bearer credentials — anyone holding them is the student — so the
 * implementation that backs this on Android must encrypt them under a key the
 * operating system holds, not under one shipped in the APK.
 *
 * This interface is deliberately free of Android types so the logic above it
 * can be tested without a device, and so a test can substitute an in-memory
 * store without weakening the real one.
 */
interface SessionStore {

    /** Cookies to send, as name to value. Empty when signed out. */
    fun cookies(): Map<String, String>

    /** Replaces the stored cookies with what the server just set. */
    fun saveCookies(cookies: Map<String, String>)

    /**
     * The CSRF token from the last page the app loaded.
     *
     * Held separately from the cookies because it is not one: the server
     * rotates it on sign-in and expects it echoed in a header on every
     * state-changing request.
     */
    var csrfToken: String?

    /** Everything, gone. Called on sign-out and whenever the server says the session is over. */
    fun clear()
}

/** A store that keeps nothing beyond the process. Tests and previews only. */
class InMemorySessionStore : SessionStore {
    private val jar = mutableMapOf<String, String>()
    override var csrfToken: String? = null

    override fun cookies(): Map<String, String> = jar.toMap()

    override fun saveCookies(cookies: Map<String, String>) {
        cookies.forEach { (name, value) ->
            // An expired cookie is the server deleting it; storing the tombstone
            // would send an empty credential back on every later request.
            if (value.isEmpty()) jar.remove(name) else jar[name] = value
        }
    }

    override fun clear() {
        jar.clear()
        csrfToken = null
    }
}
