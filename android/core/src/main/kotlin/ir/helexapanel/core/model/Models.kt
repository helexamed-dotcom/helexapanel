package ir.helexapanel.core.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * What the panel sends, as types.
 *
 * Every field the server may omit is nullable here rather than defaulted,
 * because a default hides the difference between "no teacher assigned" and
 * "the server changed shape and this field vanished" — and only one of those
 * is a bug worth finding.
 *
 * Dates arrive twice throughout: the Gregorian value for sorting, and the
 * Jalali string for reading. The client never converts between them; doing so
 * would mean a second calendar implementation that could disagree with the
 * website's.
 */

@Serializable
data class Profile(
    val uuid: String,
    @SerialName("full_name") val fullName: String,
    val username: String,
    val mobile: String? = null,
    @SerialName("phone_verified") val phoneVerified: Boolean = false,
    /** Whether one is set. The hash is never sent and never asked for. */
    @SerialName("has_password") val hasPassword: Boolean = false,
    val university: String? = null,
    val major: String? = null,
    @SerialName("avatar_url") val avatarUrl: String? = null,
    @SerialName("joined_label") val joinedLabel: String? = null
)

@Serializable
data class UnreadCounts(
    val notifications: Int = 0,
    val messages: Int = 0
) {
    val total: Int get() = notifications + messages
}

@Serializable
data class Course(
    val uuid: String,
    val title: String,
    val description: String? = null,
    /** The accent the admin chose, as "#rrggbb". Null means use the default. */
    val color: String? = null
)

@Serializable
data class ScheduleItem(
    val title: String,
    /** "HH:MM". Already trimmed server-side; the seconds were always zero. */
    val start: String,
    val end: String,
    val teacher: String? = null,
    val location: String? = null,
    val color: String? = null
)

@Serializable
data class Exam(
    val title: String,
    val kind: String,
    /** Gregorian, for ordering. */
    val date: String,
    /** Jalali, for reading. */
    @SerialName("date_label") val dateLabel: String,
    val start: String? = null,
    val location: String? = null
)

@Serializable
data class CalendarEvent(
    val title: String,
    val type: String,
    val date: String,
    @SerialName("date_label") val dateLabel: String
)

@Serializable
data class Notification(
    val id: Int,
    val title: String,
    val body: String = "",
    val type: String = "system",
    val read: Boolean = false,
    @SerialName("sent_label") val sentLabel: String
)

@Serializable
data class Section(
    val id: Int,
    @SerialName("parent_id") val parentId: Int? = null,
    val title: String,
    val type: String
)

@Serializable
data class ContentItem(
    val uuid: String,
    @SerialName("section_id") val sectionId: Int? = null,
    val title: String,
    val type: String,
    val minutes: Int? = null,
    /** unread | studying | completed | review_later */
    val status: String = "unread",
    @SerialName("seconds_read") val secondsRead: Int = 0,
    /**
     * Where to open this, never where it is stored.
     *
     * The viewer is what mints the per-open token; a file path would have no
     * token to mint, so the server does not send one and this client would not
     * know what to do with it.
     */
    @SerialName("viewer_path") val viewerPath: String
)

/* ------------------------------------------------------------- envelopes */

@Serializable
data class MeResponse(
    val user: Profile,
    val unread: UnreadCounts = UnreadCounts()
)

@Serializable
data class DashboardResponse(
    val today: List<ScheduleItem> = emptyList(),
    val exams: List<Exam> = emptyList(),
    val courses: List<Course> = emptyList(),
    val unread: UnreadCounts = UnreadCounts()
)

@Serializable
data class CoursesResponse(val courses: List<Course> = emptyList())

@Serializable
data class CourseDetailResponse(
    val course: Course,
    val sections: List<Section> = emptyList(),
    val contents: List<ContentItem> = emptyList()
)

@Serializable
data class ScheduleResponse(
    /** Persian day names, Saturday first, indexed to match `days`. */
    val weekdays: List<String> = emptyList(),
    val days: List<List<ScheduleItem>> = emptyList()
)

@Serializable
data class ExamsResponse(
    val kind: String = "final",
    val exams: List<Exam> = emptyList()
)

@Serializable
data class CalendarResponse(
    val year: Int,
    val month: Int,
    val events: List<CalendarEvent> = emptyList(),
    val exams: List<Exam> = emptyList()
)

@Serializable
data class NotificationsResponse(
    val notifications: List<Notification> = emptyList()
)
