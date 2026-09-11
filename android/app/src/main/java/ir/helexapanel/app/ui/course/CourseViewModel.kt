package ir.helexapanel.app.ui.course

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import ir.helexapanel.app.ui.components.UiState
import ir.helexapanel.core.model.CourseDetailResponse
import ir.helexapanel.core.net.ApiError
import ir.helexapanel.core.net.ApiResult
import ir.helexapanel.core.net.PanelRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * One course, with everything the student may open inside it.
 *
 * Reloads on every return rather than caching: a lesson's read state changes
 * while they are inside it, and a list that still said "unread" after they had
 * just read something would be worse than a second of loading.
 */
class CourseViewModel(
    private val panel: PanelRepository,
    private val uuid: String,
    private val onSessionLost: () -> Unit
) : ViewModel() {

    private val _state = MutableStateFlow<UiState<CourseDetailResponse>>(UiState.Loading)
    val state: StateFlow<UiState<CourseDetailResponse>> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.value = UiState.Loading

        viewModelScope.launch {
            when (val result = panel.course(uuid)) {
                is ApiResult.Success -> _state.value = UiState.Ready(result.value)

                is ApiResult.Failure -> {
                    // Same rule as every other screen: a dead session is the
                    // whole app's problem, not this list's.
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
