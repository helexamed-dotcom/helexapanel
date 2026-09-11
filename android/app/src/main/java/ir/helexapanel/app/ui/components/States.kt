package ir.helexapanel.app.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.background
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ir.helexapanel.core.net.ApiError

/**
 * The states every screen has to be able to be in.
 *
 * They live together because the alternative is each screen inventing its own,
 * and then one of them forgetting that "no network" and "nothing here yet"
 * need to look different. A screen that can only render a list is a screen
 * that shows a blank rectangle the first time something goes wrong.
 */

/**
 * Loading, as an outline of what is coming.
 *
 * A skeleton rather than a spinner: the shape tells the reader what to expect
 * and the screen does not jump when the content lands. The pulse is slow
 * enough not to be a distraction and is the only animation on the screen.
 */
@Composable
fun LoadingSkeleton(
    modifier: Modifier = Modifier,
    rows: Int = 4
) {
    val transition = rememberInfiniteTransition(label = "skeleton")
    val alpha by transition.animateFloat(
        initialValue = 0.35f,
        targetValue = 0.65f,
        animationSpec = infiniteRepeatable(tween(900), androidx.compose.animation.core.RepeatMode.Reverse),
        label = "pulse"
    )

    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        repeat(rows) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(84.dp)
                    .alpha(alpha)
                    .background(
                        MaterialTheme.colorScheme.surfaceVariant,
                        RoundedCornerShape(18.dp)
                    )
            ) {}
        }
    }
}

/**
 * Nothing here — and that is fine.
 *
 * Deliberately not styled as a problem: an empty course list on a new account
 * is the correct state, and painting it red would tell a student something is
 * broken when nothing is.
 */
@Composable
fun EmptyState(
    message: String,
    modifier: Modifier = Modifier
) {
    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text(
            text = message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center
        )
    }
}

/**
 * Something went wrong, said in a way the reader can act on.
 *
 * The message is the server's wherever the server sent one — it knows how many
 * attempts are left and how long a cooldown has to run, and this screen does
 * not. Retry is offered only where retrying could plausibly help: a stale
 * session or a forbidden page will not fix itself by pressing a button.
 */
@Composable
fun ErrorState(
    error: ApiError,
    onRetry: (() -> Unit)? = null,
    modifier: Modifier = Modifier
) {
    val retryable = when (error) {
        is ApiError.Offline, is ApiError.Timeout, is ApiError.Server, is ApiError.Unreadable -> true
        is ApiError.RateLimited -> true
        else -> false
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text(
            text = error.message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurface,
            textAlign = TextAlign.Center
        )

        if (retryable && onRetry != null) {
            Button(
                onClick = onRetry,
                modifier = Modifier.padding(top = 20.dp)
            ) {
                Text(text = androidx.compose.ui.res.stringResource(ir.helexapanel.app.R.string.state_retry))
            }
        }
    }
}

/**
 * One screen's worth of state.
 *
 * Sealed so a `when` over it cannot silently miss a case — which is exactly
 * how a screen ends up rendering nothing on an error nobody thought about.
 */
sealed class UiState<out T> {
    object Loading : UiState<Nothing>()
    data class Ready<T>(val value: T) : UiState<T>()
    data class Failed(val error: ApiError) : UiState<Nothing>()
}
