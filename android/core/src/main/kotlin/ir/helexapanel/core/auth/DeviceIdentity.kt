package ir.helexapanel.core.auth

/**
 * The User-Agent and Accept-Language this app sends, forever.
 *
 * These are not cosmetic. The panel binds every session to a fingerprint it
 * derives from exactly these two headers, and re-checks it on every request:
 * if either changes, the session is destroyed as a suspected theft and the
 * student is thrown out mid-lesson.
 *
 * So they must not carry anything that moves. No app version, no OS version,
 * no device model, no locale that follows a system setting — an OS update or
 * a version bump would silently sign out every user at once.
 *
 * The string still identifies the app honestly, so the panel's session list
 * shows "HeleXaApp" rather than something impersonating a browser.
 */
object DeviceIdentity {

    /**
     * Deliberately frozen. Changing this line signs out every installed copy
     * of the app, so it is versioned separately from the app itself and is
     * only ever changed on purpose.
     */
    const val USER_AGENT: String = "HeleXaApp/1 (Android)"

    /** Matches the panel's own language and never follows the device locale. */
    const val ACCEPT_LANGUAGE: String = "fa-IR,fa;q=0.9"
}
