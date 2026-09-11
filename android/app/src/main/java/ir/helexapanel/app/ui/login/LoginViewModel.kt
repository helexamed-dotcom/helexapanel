package ir.helexapanel.app.ui.login

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import ir.helexapanel.core.auth.AuthRepository
import ir.helexapanel.core.auth.CodeRequestOutcome
import ir.helexapanel.core.auth.SignInOutcome
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * The sign-in screen's state.
 *
 * It holds no rules. Whether a number is valid, how long a cooldown runs, how
 * many digits a code has — every one of those answers comes from
 * AuthRepository or from the server, and all of them are covered by tests that
 * run against a real panel. What is left here is sequencing: which step is on
 * screen, and what the countdown says.
 */
class LoginViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _state = MutableStateFlow(LoginState())
    val state: StateFlow<LoginState> = _state.asStateFlow()

    private var countdown: Job? = null

    init {
        // The panel has no endpoint that hands out a CSRF token, so one is
        // read from the login page before the first post. Done once, here,
        // rather than lazily on submit — a student should not wait for it.
        viewModelScope.launch { auth.primeCsrfToken() }
    }

    fun onTabSelected(tab: LoginTab) = _state.update {
        it.copy(tab = tab, message = null, isError = false)
    }

    fun onIdentifierChanged(value: String) = _state.update { it.copy(identifier = value) }
    fun onPasswordChanged(value: String) = _state.update { it.copy(password = value) }
    fun onPhoneChanged(value: String) = _state.update { it.copy(phone = value) }

    fun onCodeChanged(value: String) {
        val cleaned = ir.helexapanel.core.util.PersianDigits
            .digitsOnly(value)
            .take(_state.value.codeLength)

        _state.update { it.copy(code = cleaned) }

        // Submitting on the last digit saves a tap, and is safe because a
        // wrong code is an error rather than a lockout.
        if (cleaned.length == _state.value.codeLength && !_state.value.busy) {
            submitCode()
        }
    }

    fun signInWithPassword() {
        if (_state.value.busy) return
        working()

        viewModelScope.launch {
            val s = _state.value
            handleSignIn(auth.signInWithPassword(s.identifier, s.password))
        }
    }

    fun requestCode() {
        if (_state.value.busy) return
        working()

        viewModelScope.launch {
            when (val outcome = auth.requestCode(_state.value.phone)) {
                is CodeRequestOutcome.Sent -> {
                    _state.update {
                        it.copy(
                            busy = false,
                            step = OtpStep.Code,
                            code = "",
                            codeLength = outcome.codeLength,
                            sentToPhone = outcome.phone,
                            message = outcome.message,
                            isError = false
                        )
                    }
                    startCountdown(outcome.resendAfterSeconds)
                }

                is CodeRequestOutcome.Cooldown -> {
                    fail(outcome.message)
                    startCountdown(outcome.secondsRemaining)
                }

                is CodeRequestOutcome.Invalid -> fail(outcome.message)
                is CodeRequestOutcome.Failed -> fail(outcome.error.message)
            }
        }
    }

    fun submitCode() {
        if (_state.value.busy) return
        working()

        viewModelScope.launch {
            val s = _state.value
            handleSignIn(auth.submitCode(s.sentToPhone, s.code, s.codeLength))
        }
    }

    /** Back to the number, keeping nothing from the abandoned attempt. */
    fun changeNumber() {
        countdown?.cancel()
        _state.update {
            it.copy(step = OtpStep.Phone, code = "", message = null, isError = false, secondsLeft = 0)
        }
    }

    private fun handleSignIn(outcome: SignInOutcome) = when (outcome) {
        is SignInOutcome.Success ->
            _state.update { it.copy(busy = false, signedIn = true, message = null) }

        is SignInOutcome.MustChangePassword ->
            _state.update { it.copy(busy = false, signedIn = true, mustChangePassword = true) }

        // Correct credentials, wrong app. Saying so beats a shell with nothing
        // behind it.
        is SignInOutcome.NotAStudent ->
            fail("این اپ برای دانشجویان است. برای ورود به پنل مدیریت از وب‌سایت استفاده کن.")

        // Not a retry. The student has to decide which device they want.
        is SignInOutcome.OtherDevice ->
            _state.update { it.copy(busy = false, message = outcome.message, isError = true, otherDevice = true) }

        is SignInOutcome.Rejected -> fail(outcome.message)
        is SignInOutcome.Failed -> fail(outcome.error.message)
        is SignInOutcome.Invalid -> fail(outcome.message)
    }

    private fun working() = _state.update { it.copy(busy = true, message = null, isError = false, otherDevice = false) }

    private fun fail(message: String) = _state.update {
        it.copy(busy = false, message = message, isError = true)
    }

    /**
     * Counts down to when a resend is allowed.
     *
     * Purely so the student knows why the button is disabled — the server
     * refuses an early resend regardless of what this says.
     */
    private fun startCountdown(seconds: Int) {
        countdown?.cancel()
        countdown = viewModelScope.launch {
            var left = seconds
            while (left > 0) {
                _state.update { it.copy(secondsLeft = left) }
                delay(1000)
                left--
            }
            _state.update { it.copy(secondsLeft = 0) }
        }
    }

    override fun onCleared() {
        countdown?.cancel()
        super.onCleared()
    }
}

enum class LoginTab { Password, Sms }
enum class OtpStep { Phone, Code }

data class LoginState(
    val tab: LoginTab = LoginTab.Password,
    val step: OtpStep = OtpStep.Phone,

    val identifier: String = "",
    val password: String = "",
    val phone: String = "",
    val code: String = "",

    /** Reported by the server per send — it is not a constant. */
    val codeLength: Int = 6,
    val sentToPhone: String = "",
    val secondsLeft: Int = 0,

    val busy: Boolean = false,
    val message: String? = null,
    val isError: Boolean = false,

    /** Signed in elsewhere; needs a decision, not a retry. */
    val otherDevice: Boolean = false,

    val signedIn: Boolean = false,
    val mustChangePassword: Boolean = false
) {
    val canResend: Boolean get() = secondsLeft == 0 && !busy
}
