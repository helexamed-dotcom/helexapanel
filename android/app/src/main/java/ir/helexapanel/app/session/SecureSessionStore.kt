package ir.helexapanel.app.session

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import ir.helexapanel.core.auth.SessionStore

/**
 * Where the session lives on the device.
 *
 * What is stored here is a bearer credential: anyone holding the cookie is the
 * student, for as long as it lasts — and the student chose to stay signed in
 * for thirty days, so it lasts. That rules out plain SharedPreferences, which
 * is a world-readable-by-root XML file that survives in cloud backups.
 *
 * So it is encrypted under a key generated inside the device's hardware-backed
 * keystore. The key never leaves the keystore and is never in the APK, which
 * is the property that matters: decompiling the app yields no way to read a
 * copy of this file taken from somewhere else.
 *
 * Limits, stated rather than implied. This protects the credential at rest on
 * a device the attacker does not control. It does not protect against a rooted
 * device running as this app, and it is not meant to: at that point the
 * attacker can read the decrypted value out of memory. The bar it raises is
 * offline extraction — a pulled backup, a stolen unlocked phone, an adb dump.
 */
class SecureSessionStore(context: Context) : SessionStore {

    private val prefs: SharedPreferences = try {
        val key = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            FILE_NAME,
            key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    } catch (e: Exception) {
        /**
         * The keystore can genuinely fail: a corrupted keyset after a restore
         * to a different device, or a vendor bug on an old build.
         *
         * Falling back to unencrypted storage would be the wrong answer — it
         * would silently downgrade every user who hit it. Instead the file is
         * discarded and rebuilt, which costs the student one sign-in and keeps
         * the guarantee intact.
         */
        context.deleteSharedPreferences(FILE_NAME)

        val key = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            FILE_NAME,
            key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    override fun cookies(): Map<String, String> {
        val raw = prefs.getString(KEY_COOKIES, null) ?: return emptyMap()

        // Stored as name=value pairs separated by a newline, which cannot occur
        // inside a cookie value.
        return raw.lineSequence()
            .mapNotNull { line ->
                val parts = line.split('=', limit = 2)
                if (parts.size == 2 && parts[0].isNotBlank()) parts[0] to parts[1] else null
            }
            .toMap()
    }

    override fun saveCookies(cookies: Map<String, String>) {
        val merged = cookies().toMutableMap()

        cookies.forEach { (name, value) ->
            // An empty value is the server deleting the cookie. Keeping the
            // tombstone would send an empty credential on every later request.
            if (value.isEmpty()) merged.remove(name) else merged[name] = value
        }

        prefs.edit()
            .putString(KEY_COOKIES, merged.entries.joinToString("\n") { "${it.key}=${it.value}" })
            .apply()
    }

    override var csrfToken: String?
        get() = prefs.getString(KEY_CSRF, null)
        set(value) {
            prefs.edit().apply {
                if (value.isNullOrBlank()) remove(KEY_CSRF) else putString(KEY_CSRF, value)
            }.apply()
        }

    /**
     * Committed synchronously, not applied.
     *
     * Sign-out is one of the few moments where the write has to have happened
     * before the next thing does: if the process is killed between clearing
     * and the write landing, the credential is still on disk and the student
     * believes they signed out.
     */
    @Suppress("ApplySharedPref")
    override fun clear() {
        prefs.edit().clear().commit()
    }

    /** True when there is something worth trying a silent restore with. */
    fun hasSession(): Boolean = cookies().isNotEmpty()

    private companion object {
        const val FILE_NAME = "helexa_session"
        const val KEY_COOKIES = "cookies"
        const val KEY_CSRF = "csrf"
    }
}
