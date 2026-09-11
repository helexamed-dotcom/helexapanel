/**
 * SegmentedButton and SingleChoiceSegmentedButtonRow are stable in Compose
 * Multiplatform but still marked experimental in androidx's Material3, which
 * is what the Android build compiles against. Opting in here keeps the same
 * source compiling on both; if the annotation ever becomes unnecessary the
 * compiler says so with a warning rather than an error.
 */
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package ir.helexapanel.app.ui.login

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ir.helexapanel.app.R
import ir.helexapanel.core.util.PersianDigits

/**
 * Signing in.
 *
 * Two ways, because the panel allows two: a password the student chose, and a
 * code texted to their number. The SMS tab is also how an account is created —
 * the panel registers a new number on its first verified code — which is why
 * there is no separate sign-up screen to get out of step with it.
 *
 * The screen holds no rules. Which button is enabled, how long a countdown
 * runs, how many digits a code has: all of that is state the view model
 * computed from what the server said.
 */
@Composable
fun LoginScreen(
    model: LoginViewModel,
    onSignedIn: () -> Unit
) {
    val state by model.state.collectAsStateWithLifecycle()

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Text(
                text = stringResource(R.string.sign_in_title),
                style = MaterialTheme.typography.headlineMedium
            )

            Spacer(Modifier.height(24.dp))

            Card(
                modifier = Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
            ) {
                Column(Modifier.padding(20.dp)) {

                    SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                        SegmentedButton(
                            selected = state.tab == LoginTab.Password,
                            onClick = { model.onTabSelected(LoginTab.Password) },
                            shape = SegmentedButtonDefaults.itemShape(index = 0, count = 2)
                        ) { Text(stringResource(R.string.tab_password)) }

                        SegmentedButton(
                            selected = state.tab == LoginTab.Sms,
                            onClick = { model.onTabSelected(LoginTab.Sms) },
                            shape = SegmentedButtonDefaults.itemShape(index = 1, count = 2)
                        ) { Text(stringResource(R.string.tab_sms)) }
                    }

                    Spacer(Modifier.height(20.dp))

                    state.message?.let { message ->
                        Text(
                            text = message,
                            style = MaterialTheme.typography.bodySmall,
                            color = if (state.isError) {
                                MaterialTheme.colorScheme.error
                            } else {
                                MaterialTheme.colorScheme.onSurfaceVariant
                            },
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(bottom = 14.dp)
                        )
                    }

                    when (state.tab) {
                        LoginTab.Password -> PasswordPanel(state, model)
                        LoginTab.Sms -> SmsPanel(state, model)
                    }
                }
            }
        }
    }
}

@Composable
private fun PasswordPanel(state: LoginState, model: LoginViewModel) {
    OutlinedTextField(
        value = state.identifier,
        onValueChange = model::onIdentifierChanged,
        label = { Text(stringResource(R.string.field_identifier)) },
        singleLine = true,
        enabled = !state.busy,
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Text,
            imeAction = ImeAction.Next
        ),
        modifier = Modifier.fillMaxWidth()
    )

    Spacer(Modifier.height(12.dp))

    OutlinedTextField(
        value = state.password,
        onValueChange = model::onPasswordChanged,
        label = { Text(stringResource(R.string.field_password)) },
        singleLine = true,
        enabled = !state.busy,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(
            keyboardType = KeyboardType.Password,
            imeAction = ImeAction.Done
        ),
        modifier = Modifier.fillMaxWidth()
    )

    Spacer(Modifier.height(20.dp))

    SubmitButton(
        label = stringResource(R.string.action_sign_in),
        busy = state.busy,
        onClick = model::signInWithPassword
    )
}

@Composable
private fun SmsPanel(state: LoginState, model: LoginViewModel) {
    when (state.step) {
        OtpStep.Phone -> {
            OutlinedTextField(
                value = state.phone,
                onValueChange = model::onPhoneChanged,
                label = { Text(stringResource(R.string.field_phone)) },
                placeholder = { Text("09123456789") },
                singleLine = true,
                enabled = !state.busy,
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Phone,
                    imeAction = ImeAction.Done
                ),
                modifier = Modifier.fillMaxWidth()
            )

            Spacer(Modifier.height(20.dp))

            SubmitButton(
                label = stringResource(R.string.action_request_code),
                busy = state.busy,
                onClick = model::requestCode
            )
        }

        OtpStep.Code -> {
            OutlinedTextField(
                value = state.code,
                onValueChange = model::onCodeChanged,
                // The length is whatever the server minted: this panel's own
                // codes are six digits, the SMS provider's may be ten.
                label = {
                    Text("کد ${PersianDigits.format(state.codeLength)} رقمی پیامک‌شده")
                },
                singleLine = true,
                enabled = !state.busy,
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.NumberPassword,
                    imeAction = ImeAction.Done
                ),
                modifier = Modifier.fillMaxWidth()
            )

            Spacer(Modifier.height(8.dp))

            Text(
                text = "کد به ${PersianDigits.format(state.sentToPhone)} فرستاده شد",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Spacer(Modifier.height(16.dp))

            SubmitButton(
                label = stringResource(R.string.action_verify_code),
                busy = state.busy,
                onClick = model::submitCode
            )

            Spacer(Modifier.height(8.dp))

            // Says why the button is disabled rather than leaving a dead
            // control. The server refuses an early resend regardless.
            if (state.secondsLeft > 0) {
                Text(
                    text = "ارسال مجدد کد تا ${PersianDigits.format(state.secondsLeft)} ثانیه دیگر",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth()
                )
            } else {
                TextButton(
                    onClick = model::requestCode,
                    enabled = state.canResend,
                    modifier = Modifier.fillMaxWidth()
                ) { Text(stringResource(R.string.action_resend)) }
            }

            TextButton(
                onClick = model::changeNumber,
                enabled = !state.busy,
                modifier = Modifier.fillMaxWidth()
            ) { Text(stringResource(R.string.action_change_number)) }
        }
    }
}

@Composable
private fun SubmitButton(label: String, busy: Boolean, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        enabled = !busy,
        modifier = Modifier.fillMaxWidth()
    ) {
        if (busy) {
            CircularProgressIndicator(
                modifier = Modifier.height(18.dp),
                strokeWidth = 2.dp,
                color = MaterialTheme.colorScheme.onPrimary
            )
        } else {
            Text(label)
        }
    }
}
