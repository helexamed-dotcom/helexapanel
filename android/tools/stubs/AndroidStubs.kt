package androidx.lifecycle
abstract class ViewModel { open fun onCleared() {} }
val ViewModel.viewModelScope: kotlinx.coroutines.CoroutineScope
    get() = kotlinx.coroutines.CoroutineScope(
        kotlinx.coroutines.SupervisorJob() + kotlinx.coroutines.Dispatchers.Unconfined)
