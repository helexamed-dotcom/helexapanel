/**
 * Card's clickable overload carries an experimental marker on some Material3
 * versions. Opting in costs nothing and removes a difference between what
 * compiles here and what compiles against androidx.
 */
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package ir.helexapanel.app.ui.home

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ir.helexapanel.app.ui.components.EmptyState
import ir.helexapanel.app.ui.components.ErrorState
import ir.helexapanel.app.ui.components.LoadingSkeleton
import ir.helexapanel.app.ui.components.UiState
import ir.helexapanel.core.model.Course
import ir.helexapanel.core.model.Exam
import ir.helexapanel.core.model.ScheduleItem
import ir.helexapanel.core.util.PersianDigits

/**
 * What today looks like.
 *
 * Classes first because that is what a student opens the app to check between
 * lectures, then the exams that are coming, then the courses they can read.
 * Anything the panel has nothing to say about is left out rather than shown as
 * an empty box with a heading.
 */
@Composable
fun HomeScreen(
    model: HomeViewModel,
    onCourseClick: (Course) -> Unit,
    modifier: Modifier = Modifier
) {
    val state by model.state.collectAsStateWithLifecycle()

    when (val current = state) {
        is UiState.Loading -> LoadingSkeleton(modifier = modifier)

        is UiState.Failed -> ErrorState(
            error = current.error,
            onRetry = model::load,
            modifier = modifier
        )

        is UiState.Ready -> {
            val data = current.value

            // Everything empty at once is a real state — a new account before
            // an admin has assigned anything — and it needs saying once rather
            // than three times.
            if (data.today.isEmpty() && data.exams.isEmpty() && data.courses.isEmpty()) {
                EmptyState(
                    message = "هنوز چیزی برای نمایش نیست. وقتی مدیر دوره‌ای برایت فعال کند اینجا ظاهر می‌شود.",
                    modifier = modifier
                )
                return
            }

            LazyColumn(
                modifier = modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                if (data.today.isNotEmpty()) {
                    item { SectionHeading("کلاس‌های امروز") }
                    items(data.today) { ClassRow(it) }
                }

                if (data.exams.isNotEmpty()) {
                    item { SectionHeading("امتحان‌های پیش رو") }
                    items(data.exams) { ExamRow(it) }
                }

                if (data.courses.isNotEmpty()) {
                    item { SectionHeading("دوره‌های من") }
                    items(data.courses) { course ->
                        CourseRow(course = course, onClick = { onCourseClick(course) })
                    }
                }
            }
        }
    }
}

@Composable
private fun SectionHeading(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(top = 12.dp, bottom = 2.dp)
    )
}

@Composable
private fun ClassRow(item: ScheduleItem) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            // The time leads, because on this screen it is what the reader is
            // scanning for.
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    text = PersianDigits.format(item.start),
                    style = MaterialTheme.typography.titleMedium
                )
                Text(
                    text = PersianDigits.format(item.end),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            Spacer(Modifier.width(14.dp))

            Column(Modifier.fillMaxWidth()) {
                Text(text = item.title, style = MaterialTheme.typography.bodyLarge)

                val detail = listOfNotNull(item.teacher, item.location).joinToString(" · ")
                if (detail.isNotBlank()) {
                    Text(
                        text = detail,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }
    }
}

@Composable
private fun ExamRow(exam: Exam) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(text = exam.title, style = MaterialTheme.typography.bodyLarge)

            // The Jalali label the server rendered, never a conversion done
            // here — two implementations would eventually disagree.
            val detail = listOfNotNull(
                exam.dateLabel,
                exam.start?.let { PersianDigits.format(it) },
                exam.location
            ).joinToString(" · ")

            Text(
                text = detail,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}

@Composable
private fun CourseRow(course: Course, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(text = course.title, style = MaterialTheme.typography.bodyLarge)

            course.description?.takeIf { it.isNotBlank() }?.let {
                Spacer(Modifier.height(2.dp))
                Text(
                    text = it,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 2
                )
            }
        }
    }
}
