package ir.helexapanel.app

import android.app.Application
import ir.helexapanel.app.session.SecureSessionStore
import ir.helexapanel.core.auth.AuthRepository
import ir.helexapanel.core.net.ApiClient
import ir.helexapanel.core.net.PanelRepository
import okhttp3.OkHttpClient
import java.util.concurrent.TimeUnit

/**
 * The app's one container.
 *
 * A dependency-injection framework would buy nothing here: there are four
 * objects, they all live as long as the process, and none of them has a
 * variant. Constructing them in one place keeps that visible, and keeps a
 * second `OkHttpClient` — with its own connection pool and its own cookie
 * behaviour — from quietly appearing somewhere.
 */
class HeleXaApplication : Application() {

    val sessionStore: SecureSessionStore by lazy { SecureSessionStore(this) }

    private val http: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            /**
             * Redirects are not followed.
             *
             * The panel answers an unauthenticated page request with a 302 to
             * the login page. Following it would turn "your session expired"
             * into "here is some HTML", and the client would have to guess
             * which it got. Refusing to follow makes the status code the
             * answer.
             */
            .followRedirects(false)
            .followSslRedirects(false)
            /**
             * One retry, and only for a connection that never delivered.
             *
             * Mobile networks drop idle connections constantly; without this,
             * the first request after a few minutes on a train fails for no
             * reason a student could understand. OkHttp only retries requests
             * it knows were never received.
             */
            .retryOnConnectionFailure(true)
            .build()
    }

    val api: ApiClient by lazy { ApiClient(BuildConfig.PANEL_URL, sessionStore, http) }
    val auth: AuthRepository by lazy { AuthRepository(api, sessionStore) }
    val panel: PanelRepository by lazy { PanelRepository(api) }
}
