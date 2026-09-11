/**
 * TopAppBar and its scaffold slot are experimental on androidx's Material3.
 * The opt-in is explicit so the build does not depend on a default changing.
 */
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package ir.helexapanel.app

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.compose.viewModel
import ir.helexapanel.app.content.ContentViewerActivity
import ir.helexapanel.app.ui.course.CourseScreen
import ir.helexapanel.app.ui.course.CourseViewModel
import ir.helexapanel.app.ui.home.HomeScreen
import ir.helexapanel.app.ui.home.HomeViewModel
import ir.helexapanel.app.ui.login.LoginScreen
import ir.helexapanel.app.ui.login.LoginViewModel
import ir.helexapanel.app.ui.theme.HeleXaTheme
import ir.helexapanel.core.model.Course
import kotlinx.coroutines.launch

/**
 * The app's single activity.
 *
 * One activity, because the only screen that genuinely needs its own window is
 * the content viewer — it carries FLAG_SECURE and must be excluded from
 * recents, and both of those are window properties. Everything else is
 * composition.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        /**
         * Installed before super.onCreate, which is what lets the system draw
         * the splash from the moment the process starts rather than after the
         * first frame.
         *
         * It is also what swaps the launcher theme for the real one: the
         * manifest's theme is Theme.HeleXa.Splash, and postSplashScreenTheme
         * only takes effect because this call applies it. Without it the app
         * runs under the splash theme for its whole life.
         */
        installSplashScreen()

        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        val app = application as HeleXaApplication

        setContent {
            HeleXaTheme {
                /**
                 * A session on disk is a reason to try, not proof of being
                 * signed in — the panel may have ended it, or another device
                 * may have taken it under the one-device rule. The real answer
                 * comes from the first API call, and every screen reports a
                 * refusal back here rather than rendering it itself.
                 */
                var signedIn by remember { mutableStateOf(app.sessionStore.hasSession()) }

                if (signedIn) {
                    SignedIn(
                        app = app,
                        onSignedOut = { signedIn = false },
                        onOpenPath = { path ->
                            startActivity(
                                Intent(this, ContentViewerActivity::class.java)
                                    .putExtra(ContentViewerActivity.EXTRA_PATH, path)
                            )
                        }
                    )
                } else {
                    val model: LoginViewModel = viewModel(
                        factory = object : ViewModelProvider.Factory {
                            @Suppress("UNCHECKED_CAST")
                            override fun <T : ViewModel> create(modelClass: Class<T>): T =
                                LoginViewModel(app.auth) as T
                        }
                    )

                    LoginScreen(model = model, onSignedIn = { signedIn = true })
                }
            }
        }
    }
}

/**
 * Everything behind the sign-in wall.
 *
 * Navigation is one nullable value rather than a navigation graph: there are
 * two destinations, and a graph would be more machinery than the thing it
 * describes. When there are five, it earns its place.
 */
@Composable
private fun SignedIn(
    app: HeleXaApplication,
    onSignedOut: () -> Unit,
    onOpenPath: (String) -> Unit
) {
    var openCourse by remember { mutableStateOf<Course?>(null) }
    var confirmingSignOut by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    // Inside a course, back means "back to the list" before it means "leave
    // the app".
    BackHandler(enabled = openCourse != null) { openCourse = null }

    /**
     * Sign-out, done in the order that survives being interrupted.
     *
     * The server is told first, so the session is dead even if the process is
     * killed a moment later; then every local trace goes, cookies included.
     * A failed request does not stop the local wipe — a student who asked to
     * sign out on a phone with no signal must still end up signed out on it.
     */
    fun signOut() {
        scope.launch {
            app.auth.signOut()
            ContentViewerActivity.purgeAll(app, app.sessionStore)
            onSignedOut()
        }
    }

    if (confirmingSignOut) {
        AlertDialog(
            onDismissRequest = { confirmingSignOut = false },
            title = { Text(stringResource(R.string.sign_out_confirm_title)) },
            text = { Text(stringResource(R.string.sign_out_confirm_body)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmingSignOut = false
                    signOut()
                }) { Text(stringResource(R.string.action_sign_out)) }
            },
            dismissButton = {
                TextButton(onClick = { confirmingSignOut = false }) {
                    Text(stringResource(R.string.action_cancel))
                }
            }
        )
    }

    val course = openCourse

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = course?.title ?: stringResource(R.string.app_name),
                        style = MaterialTheme.typography.titleLarge,
                        maxLines = 1
                    )
                },
                // In an RTL layout the navigation slot sits on the right,
                // which is where a Persian reader looks for "back".
                navigationIcon = {
                    if (course != null) {
                        TextButton(onClick = { openCourse = null }) {
                            Text(stringResource(R.string.cd_back))
                        }
                    }
                },
                actions = {
                    if (course == null) {
                        TextButton(onClick = { confirmingSignOut = true }) {
                            Text(stringResource(R.string.action_sign_out))
                        }
                    }
                }
            )
        }
    ) { padding ->
        val content = Modifier
            .fillMaxSize()
            .padding(padding)

        if (course == null) {
            /**
             * Keyed to nothing, so it survives returning from a course and the
             * dashboard is not refetched every time the student comes back.
             */
            val model: HomeViewModel = viewModel(
                key = "home",
                factory = object : ViewModelProvider.Factory {
                    @Suppress("UNCHECKED_CAST")
                    override fun <T : ViewModel> create(modelClass: Class<T>): T =
                        HomeViewModel(app.panel, onSessionLost = onSignedOut) as T
                }
            )

            HomeScreen(
                model = model,
                onCourseClick = { openCourse = it },
                onSignOut = ::signOut,
                modifier = content
            )
        } else {
            // Keyed by course, so opening a second one does not show the
            // first one's contents while the second loads.
            val model: CourseViewModel = viewModel(
                key = "course-${course.uuid}",
                factory = object : ViewModelProvider.Factory {
                    @Suppress("UNCHECKED_CAST")
                    override fun <T : ViewModel> create(modelClass: Class<T>): T =
                        CourseViewModel(app.panel, course.uuid, onSessionLost = onSignedOut) as T
                }
            )

            CourseScreen(
                model = model,
                onOpenContent = { onOpenPath(it.viewerPath) },
                modifier = content
            )
        }
    }
}
