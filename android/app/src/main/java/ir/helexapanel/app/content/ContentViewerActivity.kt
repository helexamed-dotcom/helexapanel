package ir.helexapanel.app.content

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import ir.helexapanel.app.BuildConfig
import ir.helexapanel.app.session.SecureSessionStore
import java.net.URI

/**
 * Where a lesson is read.
 *
 * The panel's teaching material is HTML — notes, question banks, mind maps,
 * the Balin case player — authored as documents with their own styling and
 * scripts. Re-implementing that natively would mean writing a second renderer
 * and having it disagree with the website, so this is the one place the app
 * uses a WebView, and it is locked down to within an inch of its life.
 *
 * What the lockdown is actually for: the document arrives through the panel's
 * viewer, which mints a single-use token bound to the session, the user agent
 * and the IP prefix, and expires it in seconds. That token is worth very
 * little once spent. The value worth protecting is the rendered lesson, and
 * the ways it could leave this screen are: a screenshot, a share sheet, a
 * download, a print, a copy to another app, or a cached copy left on disk.
 * Each of those is closed below.
 *
 * What this is not: a guarantee. Anything drawn on a screen can be
 * photographed, and a rooted device can be made to do most things. The goal is
 * that a lesson cannot be extracted casually, cannot be reused offline, and
 * leaves nothing behind — not that extraction is impossible.
 */
class ContentViewerActivity : ComponentActivity() {

    private lateinit var webView: WebView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        /**
         * Blocks screenshots, screen recording, and the thumbnail the system
         * takes for the recents switcher.
         *
         * Set before any content is attached, because the flag applies to the
         * window from the moment it is added — setting it later leaves a frame
         * where a capture would succeed.
         */
        window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)

        val path = intent.getStringExtra(EXTRA_PATH)
        if (path.isNullOrBlank() || !path.startsWith("/")) {
            // A viewer with nothing to view is a bug, not a screen.
            finish()
            return
        }

        webView = WebView(this).apply { configure() }
        setContentView(webView)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                // Inside a lesson, back means "back through the lesson" until
                // there is nowhere left to go, then out.
                if (webView.canGoBack()) webView.goBack() else finish()
            }
        })

        webView.loadUrl(BuildConfig.PANEL_URL.trimEnd('/') + path)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun WebView.configure() {
        // The panel's own cookies are how the viewer knows who is asking. They
        // are the only cookies this WebView is allowed to hold.
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(this@configure, false)
        }

        settings.apply {
            // On, and the reason is specific: the lesson player, the highlight
            // tool and the Balin stepper are script. Without it the content is
            // a wall of text with dead buttons.
            javaScriptEnabled = true

            // Off, all of it. None of the panel's content reads from disk, and
            // each of these is a documented path from a malicious document to
            // the app's private files.
            allowFileAccess = false
            allowContentAccess = false
            @Suppress("DEPRECATION")
            allowFileAccessFromFileURLs = false
            @Suppress("DEPRECATION")
            allowUniversalAccessFromFileURLs = false

            // A lesson served over https must not be able to pull a script
            // over http — that is how a rendered document gets rewritten in
            // transit on a hostile network.
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW

            // Nothing about a lesson is worth keeping on disk. Off means the
            // next reader of this device finds no copy.
            cacheMode = WebSettings.LOAD_NO_CACHE
            domStorageEnabled = false
            databaseEnabled = false
            @Suppress("DEPRECATION")
            saveFormData = false

            // No geolocation prompt in a document that has no business asking.
            setGeolocationEnabled(false)

            // A second window is a window this screen does not control, and
            // therefore one without FLAG_SECURE.
            setSupportMultipleWindows(false)
            javaScriptCanOpenWindowsAutomatically = false

            // Text scales with the reader's system setting, which is an
            // accessibility requirement, but the page cannot be pinch-zoomed
            // into a layout the stylesheet never anticipated.
            builtInZoomControls = false
            displayZoomControls = false

            // Honest about who is asking. The session's fingerprint is bound
            // to this string on the server, so it matches what the API client
            // sends — a mismatch would destroy the session mid-lesson.
            userAgentString = ir.helexapanel.core.auth.DeviceIdentity.USER_AGENT
        }

        // Long-press selection is how a lesson gets copied into another app.
        isLongClickable = false
        setOnLongClickListener { true }

        // There is no download in this app. Refusing here means a link that
        // would have saved a file does nothing instead of writing one to a
        // public directory.
        setDownloadListener { _, _, _, _, _ -> /* deliberately nothing */ }

        webViewClient = PanelOnlyClient()
    }

    /**
     * Keeps the WebView on the panel.
     *
     * A document that tried to navigate elsewhere — an injected advert, a
     * phishing redirect, an intent:// URL — would be carrying the session
     * cookie to somewhere it does not belong, or handing the reader to another
     * app entirely. Every request is checked against the panel's host, and
     * anything else is dropped rather than opened.
     */
    private inner class PanelOnlyClient : WebViewClient() {

        private val allowedHost: String = URI(BuildConfig.PANEL_URL).host

        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val host = request.url.host
            // true means "handled" — which here means refused.
            return host == null || !host.equals(allowedHost, ignoreCase = true)
        }

        override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? {
            val host = request.url.host

            // Subresources too: a stylesheet or a script pulled from another
            // host is the same problem as a navigation, and is not visible in
            // shouldOverrideUrlLoading.
            if (host != null && !host.equals(allowedHost, ignoreCase = true)) {
                return WebResourceResponse("text/plain", "utf-8", null)
            }
            return null
        }

        override fun onReceivedSslError(
            view: WebView,
            handler: android.webkit.SslErrorHandler,
            error: android.net.http.SslError
        ) {
            // Never proceed. A certificate that does not check out on the way
            // to a lesson is a certificate belonging to whoever is between the
            // student and the panel.
            handler.cancel()
            finish()
        }
    }

    /**
     * Leaves nothing behind.
     *
     * Called on the way out rather than only on sign-out: a lesson closed is a
     * lesson that should stop existing on the device, whether or not the
     * session continues.
     */
    override fun onDestroy() {
        if (::webView.isInitialized) {
            webView.apply {
                loadUrl("about:blank")
                clearHistory()
                clearCache(true)
                clearFormData()
                @Suppress("DEPRECATION")
                clearSslPreferences()
                removeAllViews()
                destroy()
            }
        }
        super.onDestroy()
    }

    companion object {
        const val EXTRA_PATH = "path"

        /**
         * Wipes every trace a WebView may have kept.
         *
         * Called on sign-out. The cookie jar is the session itself; the rest is
         * whatever the platform decided to keep despite being told not to.
         */
        fun purgeAll(context: android.content.Context, store: SecureSessionStore) {
            CookieManager.getInstance().apply {
                removeAllCookies(null)
                flush()
            }
            WebStorageCompat.deleteAll(context)
            store.clear()
        }
    }
}

/** Small indirection so the sign-out path does not depend on a WebView existing. */
private object WebStorageCompat {
    fun deleteAll(context: android.content.Context) {
        android.webkit.WebStorage.getInstance().deleteAllData()
        // Needs a real context: WebViewDatabase is per-app-storage, and passing
        // null here throws rather than doing nothing.
        android.webkit.WebViewDatabase.getInstance(context).clearHttpAuthUsernamePassword()
    }
}
