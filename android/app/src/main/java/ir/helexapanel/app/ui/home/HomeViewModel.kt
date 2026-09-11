package ir.helexapanel.app.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import ir.helexapanel.app.ui.components.UiState
import ir.helexapanel.core.model.DashboardResponse
import ir.helexapanel.core.net.ApiError
import ir.helexapanel.core.net.ApiResult
import ir.helexapanel.core.net.PanelRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * The home screen.
 *
 * Loads once on creation and on demand after that. There is no polling and no
 * background refresh: a student opens this to see today's classes, and a list
 * that silently redrew itself while they were reading it would be worse than
 * one that waited to be asked.
 */
class HomeViewModel(
    private val panel: PanelRepository,
    private val onSessionLost: () -> Unit
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<DashboardResponse>>(UiState.Loading)
    val state: StateFlow<UiState<DashboardResponse>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.value = UiState.Loading

        viewModelScope.launch {
            when (val result = panel.dashboard()) {
                is ApiResult.Success -> _state.value = UiState.Ready(result.value)

                is ApiResult.Failure -> {
                    // A dead session is not this screen's problem to render.
                    // It means the whole app should be showing sign-in, and
                    // only one place can decide that.
                    if (result.error is ApiError.Unauthorized) {
                        onSessionLost()
                    } else {
                        _state.value = UiState.Failed(result.error)
                    }
                }
            }
        }
    }
}
