/**
 * Card's clickable overload is marked experimental on androidx's Material3,
 * though not on the Compose Multiplatform build the type-check runs against.
 * Opting in explicitly removes the difference.
 */
@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package ir.helexapanel.app.ui.course

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
import ir.helexapanel.core.model.ContentItem
import ir.helexapanel.core.model.Section
import ir.helexapanel.core.util.PersianDigits

/**
 * Inside one course.
 *
 * Sections in the order the admin arranged them, each holding the lessons the
 * student may open. A lesson is opened by handing its viewer path upward —
 * this screen never sees a file, because the server never sends one.
 */
@Composable
fun CourseScreen(
    model: CourseViewModel,
    onOpenContent: (ContentItem) -> Unit,
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

            if (data.contents.isEmpty()) {
                EmptyState(
                    message = "این دوره هنوز محتوایی ندارد.",
                    modifier = modifier
                )
                return
            }

            /**
             * Grouped by section, but never at the cost of hiding something.
             *
             * A content item whose section was deleted, or which never had
             * one, still has to appear — so anything left over after the
             * known sections is collected and shown under its own heading
             * rather than silently dropped.
             */
            val bySection = data.contents.groupBy { it.sectionId }
            val known = data.sections.map { it.id }.toSet()
            val orphans = data.contents.filter { it.sectionId == null || it.sectionId !in known }

            LazyColumn(
                modifier = modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                data.sections.forEach { section ->
                    val items = bySection[section.id].orEmpty()
                    if (items.isEmpty()) return@forEach

                    item(key = "section-${section.id}") { SectionHeading(section) }

                    items(items.size, key = { "c-${items[it].uuid}" }) { index ->
                        ContentRow(item = items[index], onClick = { onOpenContent(items[index]) })
                    }
                }

                if (orphans.isNotEmpty()) {
                    if (data.sections.isNotEmpty()) {
                        item(key = "other") { Heading("سایر") }
                    }
                    items(orphans.size, key = { "o-${orphans[it].uuid}" }) { index ->
                        ContentRow(item = orphans[index], onClick = { onOpenContent(orphans[index]) })
                    }
                }
            }
        }
    }
}

@Composable
private fun SectionHeading(section: Section) = Heading(section.title)

@Composable
private fun Heading(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(top = 12.dp, bottom = 2.dp)
    )
}

@Composable
private fun ContentRow(item: ContentItem, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(14.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.fillMaxWidth()) {
                Text(text = item.title, style = MaterialTheme.typography.bodyLarge)

                // Only what is actually known. A lesson with no duration
                // recorded gets no "۰ دقیقه" that would read as a fact.
                val detail = listOfNotNull(
                    statusLabel(item.status),
                    item.minutes?.takeIf { it > 0 }?.let { "${PersianDigits.format(it.toString())} دقیقه" }
                ).joinToString(" · ")

                if (detail.isNotBlank()) {
                    Spacer(Modifier.height(2.dp))
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

/**
 * The four states the panel records, in the panel's own words.
 *
 * An unknown value is shown as nothing rather than guessed at: the server may
 * add a fifth, and an app that invented a label for it would be lying.
 */
private fun statusLabel(status: String): String? = when (status) {
    "completed" -> "خوانده شده"
    "studying" -> "در حال مطالعه"
    "review_later" -> "برای مرور"
    "unread" -> null
    else -> null
}
