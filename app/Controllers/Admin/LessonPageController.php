<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\LessonRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\RichText;
use HeleXa\Services\SharedTags;

/**
 * The inside of a درسنامه: its زیردرس‌ها (the فهرست) and the pages of each,
 * every page with its own title, body and shared tags.
 */
final class LessonPageController extends Controller
{
    private LessonRepository $lessons;

    public function __construct()
    {
        $this->lessons = new LessonRepository();
    }

    /* ------------------------------------------------------ زیردرس‌ها */

    public function addSection(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $added = 0;
        // One per line, so a whole فهرست can be pasted at once.
        foreach (array_slice(preg_split('/\R/u', $request->string('title')) ?: [], 0, 40) as $line) {
            $title = trim(mb_substr($line, 0, 191));
            if ($title !== '') {
                $this->lessons->addSection((int) $lesson['id'], $title);
                $added++;
            }
        }
        $this->flash($added ? 'success' : 'error', $added ? fa((string) $added) . ' زیردرس اضافه شد.' : 'عنوان زیردرس را بنویسید.');
        return $this->toOutline($lesson);
    }

    public function renameSection(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $section = $this->sectionOr404($lesson, $params);
        $title = trim(mb_substr($request->string('title'), 0, 191));
        if ($title !== '') {
            $this->lessons->renameSection((int) $section['id'], $title);
        }
        return $this->toOutline($lesson, 's' . $section['id']);
    }

    public function deleteSection(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $section = $this->sectionOr404($lesson, $params);
        $this->lessons->deleteSection((int) $lesson['id'], (int) $section['id']);
        ActivityLogger::log('lesson.section_deleted', Auth::id(), 'lesson', (int) $lesson['id'], ['section' => $section['title']], 'warning', $request);
        $this->flash('success', 'زیردرس «' . $section['title'] . '» و صفحه‌هایش حذف شد.');
        return $this->toOutline($lesson);
    }

    public function moveSection(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $section = $this->sectionOr404($lesson, $params);
        $this->lessons->moveSection((int) $lesson['id'], (int) $section['id'], $request->int('dir'));
        return $this->toOutline($lesson, 's' . $section['id']);
    }

    /* ---------------------------------------------------------- pages */

    public function create(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $outline = $this->lessons->outline((int) $lesson['id']);
        if ($outline === []) {
            // A page needs a زیردرس; the first one is named after the درسنامه.
            $this->lessons->addSection((int) $lesson['id'], (string) $lesson['title']);
            $outline = $this->lessons->outline((int) $lesson['id']);
        }
        $sectionId = $request->int('section') ?: (int) $outline[count($outline) - 1]['id'];
        // A new page starts with the tags of the page before it in its زیردرس.
        $tagIds = [];
        foreach ($outline as $s) {
            if ((int) $s['id'] === $sectionId && $s['pages'] !== []) {
                $tagIds = array_column($s['pages'][count($s['pages']) - 1]['tags'], 'id');
            }
        }
        return $this->form($lesson, null, $outline, $sectionId, $tagIds);
    }

    public function edit(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $page = $this->pageOr404($lesson, $params);
        return $this->form($lesson, $page, $this->lessons->outline((int) $lesson['id']), (int) $page['section_id'],
            array_column($this->lessons->pageTagsFor([(int) $page['id']])[(int) $page['id']] ?? [], 'id'));
    }

    public function store(Request $request, array $params = []): Response
    {
        return $this->persist($request, $this->lessonOr404($params), null);
    }

    public function update(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        return $this->persist($request, $lesson, $this->pageOr404($lesson, $params));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $page = $this->pageOr404($lesson, $params);
        $this->lessons->deletePage((int) $lesson['id'], (int) $page['id']);
        ActivityLogger::log('lesson.page_deleted', Auth::id(), 'lesson', (int) $lesson['id'], ['page' => $page['title']], 'warning', $request);
        $this->flash('success', 'صفحه «' . $page['title'] . '» حذف شد.');
        return $this->toOutline($lesson, 's' . $page['section_id']);
    }

    public function move(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404($params);
        $page = $this->pageOr404($lesson, $params);
        $this->lessons->movePage((int) $lesson['id'], $page, $request->int('dir'));
        return $this->toOutline($lesson, 's' . $page['section_id']);
    }

    /* -------------------------------------------------------- internals */

    private function form(array $lesson, ?array $page, array $outline, int $sectionId, array $tagIds): Response
    {
        $flat = [];
        foreach ($outline as $s) {
            foreach ($s['pages'] as $p) {
                $flat[] = $p;
            }
        }
        $prev = $next = null;
        if ($page !== null) {
            foreach ($flat as $i => $p) {
                if ((int) $p['id'] === (int) $page['id']) {
                    $prev = $flat[$i - 1] ?? null;
                    $next = $flat[$i + 1] ?? null;
                }
            }
        }
        return $this->page('layouts.app', 'admin.lessons.page', [
            'title'     => $page === null ? 'صفحه تازه — ' . $lesson['title'] : $page['title'] . ' — ' . $lesson['title'],
            'lesson'    => $lesson,
            'pageRow'   => $page,
            'outline'   => $outline,
            'sectionId' => $sectionId,
            'tagIds'    => array_map('intval', $tagIds),
            'allTags'   => SharedTags::all(),
            'prev'      => $prev,
            'next'      => $next,
            'extraCss'  => ['lessons'],
            'extraJs'   => ['lesson-editor'],
        ]);
    }

    private function persist(Request $request, array $lesson, ?array $page): Response
    {
        $title = trim(mb_substr($request->string('title'), 0, 191));
        $body  = RichText::clean((string) $request->input('body_html', ''));
        $section = $this->lessons->section((int) $lesson['id'], $request->int('section_id'));
        $base = '/admin/lessons/' . $lesson['uuid'];
        if ($title === '' || $section === null || (RichText::plain($body) === '' && !str_contains($body, '<img'))) {
            $this->flash('error', 'عنوان صفحه، زیردرس و متن صفحه لازم است.');
            return $this->redirect($page === null ? $base . '/pages/new?section=' . $request->int('section_id') : $base . '/pages/' . $page['uuid'] . '/edit');
        }
        $id = $this->lessons->savePage((int) $lesson['id'], $page, [
            'section_id'      => (int) $section['id'],
            'title'           => $title,
            'body_html'       => $body,
            'reading_minutes' => RichText::readingMinutes($body),
        ]);
        $this->lessons->syncPageTags((int) $lesson['id'], $id, SharedTags::fromRequest($request));
        ActivityLogger::log($page === null ? 'lesson.page_created' : 'lesson.page_updated', Auth::id(), 'lesson', (int) $lesson['id'], ['page' => $title], 'info', $request);
        $this->flash('success', 'صفحه ذخیره شد.');

        $saved = $this->lessons->selectPageUuid($id);
        return match ($request->string('then')) {
            'new'  => $this->redirect($base . '/pages/new?section=' . (int) $section['id']),
            'stay' => $this->redirect($base . '/pages/' . $saved . '/edit'),
            default => $this->redirect($base . '/edit#s' . (int) $section['id']),
        };
    }

    private function toOutline(array $lesson, string $anchor = 'outline'): Response
    {
        return $this->redirect('/admin/lessons/' . $lesson['uuid'] . '/edit#' . $anchor);
    }

    private function lessonOr404(array $params): array
    {
        $lesson = LessonRepository::pagesReady() ? $this->lessons->findByUuid((string) ($params['uuid'] ?? '')) : null;
        if ($lesson === null) {
            throw HttpException::notFound();
        }
        return $lesson;
    }

    private function sectionOr404(array $lesson, array $params): array
    {
        $section = $this->lessons->section((int) $lesson['id'], (int) ($params['id'] ?? 0));
        if ($section === null) {
            throw HttpException::notFound();
        }
        return $section;
    }

    private function pageOr404(array $lesson, array $params): array
    {
        $page = $this->lessons->page((int) $lesson['id'], (string) ($params['page'] ?? ''));
        if ($page === null) {
            throw HttpException::notFound();
        }
        return $page;
    }
}
