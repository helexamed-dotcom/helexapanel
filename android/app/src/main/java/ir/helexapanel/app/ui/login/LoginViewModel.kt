package ir.helexapanel.app.ui.login

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import ir.helexapanel.core.auth.AuthRepository
import ir.helexapanel.core.auth.SignInOutcome
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * The sign-in screen's state.
 *
 * Password only. The panel also supports signing in with a texted code, and
 * `AuthRepository` still implements it — but the operator does not want that
 * route in the app, so it is not offered here. Re-enabling it is a change to
 * this file and the screen, not to anything that was removed.
 *
 * No rules live here. Whether credentials are right, whether the account is
 * locked, whether it is already open on another device: all of that is the
 * server's answer, and the repository turns it into one of the outcomes below.
 */
class LoginViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _state = MutableStateFlow(LoginState())
    val state: StateFlow<LoginState> = _state.asStateFlow()

    init {
        // The panel has no endpoint that hands out a CSRF token, so one is
        // read from the login page before the first post. Done here rather
        // than lazily on submit, so a student never waits for it.
        viewModelScope.launch { auth.primeCsrfToken() }
    }

    fun onIdentifierChanged(value: String) = _state.update {
        it.copy(identifier = value, message = null, isError = false)
    }

    fun onPasswordChanged(value: String) = _state.update {
        it.copy(password = value, message = null, isError = false)
    }

    fun signIn() {
        if (_state.value.busy) return

        _state.update { it.copy(busy = true, message = null, isError = false, otherDevice = false) }

        viewModelScope.launch {
            val s = _state.value
            handle(auth.signInWithPassword(s.identifier, s.password))
        }
    }

    private fun handle(outcome: SignInOutcome) = when (outcome) {
        is SignInOutcome.Success ->
            _state.update { it.copy(busy = false, signedIn = true, message = null) }

        is SignInOutcome.MustChangePassword ->
            _state.update { it.copy(busy = false, signedIn = true, mustChangePassword = true) }

        // Correct credentials, wrong app. Saying so beats a shell with nothing
        // behind it.
        is SignInOutcome.NotAStudent ->
            fail("این اپ برای دانشجویان است. برای ورود به پنل مدیریت از وب‌سایت استفاده کن.")

        /**
         * Not a retry.
         *
         * The panel allows one device per account, so this means the student
         * is signed in somewhere else and has to decide which they want. A
         * button that said "try again" would be lying.
         */
        is SignInOutcome.OtherDevice ->
            _state.update {
                it.copy(busy = false, message = outcome.message, isError = true, otherDevice = true)
            }

        is SignInOutcome.Rejected -> fail(outcome.message)
        is SignInOutcome.Failed -> fail(outcome.error.message)
        is SignInOutcome.Invalid -> fail(outcome.message)
    }

    private fun fail(message: String) = _state.update {
        it.copy(busy = false, message = message, isError = true)
    }
}

data class LoginState(
    val identifier: String = "",
    val password: String = "",

    val busy: Boolean = false,
    val message: String? = null,
    val isError: Boolean = false,

    /** Signed in elsewhere; needs a decision, not a retry. */
    val otherDevice: Boolean = false,

    val signedIn: Boolean = false,
    val mustChangePassword: Boolean = false
) {
    /** Both fields filled and nothing in flight. */
    val canSubmit: Boolean
        get() = identifier.isNotBlank() && password.isNotEmpty() && !busy
}
