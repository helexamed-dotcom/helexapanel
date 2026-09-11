package ir.helexapanel.core.net

import ir.helexapanel.core.model.CalendarResponse
import ir.helexapanel.core.model.CourseDetailResponse
import ir.helexapanel.core.model.CoursesResponse
import ir.helexapanel.core.model.DashboardResponse
import ir.helexapanel.core.model.ExamsResponse
import ir.helexapanel.core.model.MeResponse
import ir.helexapanel.core.model.NotificationsResponse
import ir.helexapanel.core.model.ScheduleResponse
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.decodeFromJsonElement

/**
 * Everything the app reads from the panel.
 *
 * One place, so a screen cannot invent a path or forget a header, and so the
 * decoding of each envelope is written once. Each method returns a typed
 * result: a failure here is a value, not an exception, because every caller
 * has to render something for it.
 */
class PanelRepository(private val api: ApiClient) {

    private val json = Json {
        // The server may grow fields. An older build must keep working rather
        // than failing to parse a reply that is a superset of what it knows.
        ignoreUnknownKeys = true
        isLenient = true
    }

    suspend fun me() = decode<MeResponse>("/api/mobile/me")

    suspend fun dashboard() = decode<DashboardResponse>("/api/mobile/dashboard")

    suspend fun courses() = decode<CoursesResponse>("/api/mobile/courses")

    suspend fun course(uuid: String) =
        decode<CourseDetailResponse>("/api/mobile/courses/$uuid")

    suspend fun schedule() = decode<ScheduleResponse>("/api/mobile/schedule")

    suspend fun exams(kind: String = "final") =
        decode<ExamsResponse>("/api/mobile/exams?kind=$kind")

    suspend fun calendar(year: Int? = null, month: Int? = null): ApiResult<CalendarResponse> {
        val query = when {
            year != null && month != null -> "?year=$year&month=$month"
            else -> ""
        }
        return decode("/api/mobile/calendar$query")
    }

    suspend fun notifications() = decode<NotificationsResponse>("/api/mobile/notifications")

    suspend fun markNotificationRead(id: Int): ApiResult<Unit> =
        when (val result = api.post("/api/mobile/notifications/$id/read")) {
            is ApiResult.Success -> ApiResult.Success(Unit)
            is ApiResult.Failure -> result
        }

    /**
     * Fetches and decodes, turning a shape the client cannot read into
     * `Unreadable` rather than a crash.
     *
     * A decode failure is genuinely different from a server error: it means
     * this build and that server disagree about the contract, which retrying
     * will not fix. Saying so is more useful than a spinner that never stops.
     */
    private suspend inline fun <reified T> decode(path: String): ApiResult<T> =
        when (val result = api.get(path)) {
            is ApiResult.Failure -> result
            is ApiResult.Success -> try {
                ApiResult.Success(json.decodeFromJsonElement<T>(result.value))
            } catch (e: Exception) {
                ApiResult.Failure(ApiError.Unreadable())
            }
        }
}
