package ir.helexapanel.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewmodel.compose.viewModel
import ir.helexapanel.app.ui.login.LoginScreen
import ir.helexapanel.app.ui.login.LoginViewModel
import ir.helexapanel.app.ui.theme.HeleXaTheme

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
        // Installed before super.onCreate, which is what lets the system draw
        // the splash from the moment the process starts rather than after the
        // first frame. Nothing here delays anything: there is no artificial
        // minimum duration.
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
                 * comes from the first API call.
                 */
                var signedIn by remember { mutableStateOf(app.sessionStore.hasSession()) }

                if (signedIn) {
                    HomePlaceholder()
                } else {
                    val model: LoginViewModel = viewModel(
                        factory = object : ViewModelProvider.Factory {
                            @Suppress("UNCHECKED_CAST")
                            override fun <T : ViewModel> create(modelClass: Class<T>): T =
                                LoginViewModel(app.auth) as T
                        }
                    )

                    LoginScreen(
                        model = model,
                        onSignedIn = { signedIn = true }
                    )
                }
            }
        }
    }
}

/**
 * Stands in for the signed-in app until the screens are built.
 *
 * Deliberately honest rather than a fake dashboard: an empty shell that looked
 * finished would hide how much is left.
 */
@Composable
private fun HomePlaceholder() {
    Scaffold { padding ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
            contentAlignment = Alignment.Center
        ) {
            Text(
                text = "وارد شدی.\nصفحه‌های اصلی هنوز ساخته نشده‌اند.",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}
