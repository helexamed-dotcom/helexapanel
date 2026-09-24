<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\QuestionBank;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\QuestionBank\QbAccess;
use HeleXa\Services\Settings;

/**
 * The bank's overview, its syllabus tree and its tags.
 *
 * Everything that shapes where a question can be filed lives here; the
 * questions themselves are in QuestionController. The split follows the
 * permissions: an admin can be allowed to write questions without being
 * allowed to reshape the syllabus they are filed under.
 */
final class TaxonomyController extends Controller
{
    private QbSubjectRepository $subjects;
    private QbTagRepository $tags;

    public function __construct()
    {
        $this->subjects = new QbSubjectRepository();
        $this->tags     = new QbTagRepository();
    }

    /* ---------------------------------------------------------- overview */

    public function dashboard(Request $request, array $params = []): Response
    {
        $questions = new QbQuestionRepository();

        return $this->page('layouts.app', 'admin.qbank.dashboard', [
            'title'   => 'بانک سوال',
            'status'  => QbAccess::status(),
            'statuses'=> QbAccess::STATUSES,
            'comingSoonText' => QbAccess::comingSoonText(),
            'imageMaxKb'     => (int) Settings::get('qbank_image_max_kb', 3072),
            'stats'   => [
                'total'     => $questions->countMatching([]),
                'published' => $questions->countMatching(['status' => 'published']),
                'draft'     => $questions->countMatching(['status' => 'draft']),
                'unfiled'   => $questions->countMatching(['subject_id' => 'unfiled']),
                'subjects'  => count($this->subjects->roots(false)),
                'tags'      => count($this->tags->all()),
            ],
            'roots'        => $this->subjects->roots(false),
            'accessCounts' => (new QbAccessRepository())->countsBySubject(),
        ]);
    }

    /** The publication switch and its two companion settings. */
    public function saveSettings(Request $request, array $params = []): Response
    {
        $status = $request->string('status');
        if (!isset(QbAccess::STATUSES[$status])) {
            $this->flash('error', 'وضعیت انتخاب‌شده معتبر نیست.');
            return $this->redirect('/admin/qbank');
        }

        $text = mb_substr(trim($request->string('coming_soon_text')), 0, 500);
        $kb   = max(256, min($request->int('image_max_kb', 3072), 20480));

        $repo   = new SettingRepository();
        $userId = Auth::id();
        $before = QbAccess::status();

        $repo->set('qbank_status', $status, 'string', $userId);
        $repo->set('qbank_coming_soon_text', $text, 'string', $userId);
        $repo->set('qbank_image_max_kb', (string) $kb, 'int', $userId);
        $repo->set('qbank_xp_enabled', $request->bool('xp_enabled') ? '1' : '0', 'bool', $userId);
        foreach (array_keys(\HeleXa\Services\QuestionBank\QbXp::DEFAULTS) as $level) {
            $repo->set('qbank_xp_' . $level, (string) max(0, min($request->int('xp_' . $level), 500)), 'int', $userId);
        }
        Settings::flush();

        ActivityLogger::log('qbank.settings', $userId, 'setting', null,
            ['status_from' => $before, 'status_to' => $status], 'notice', $request);

        $this->flash('success', 'تنظیمات بانک سوال ذخیره شد.');
        return $this->redirect('/admin/qbank');
    }

    /* ---------------------------------------------------------- subjects */

    public function subjects(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.qbank.subjects', [
            'title'       => 'دروس و سرفصل‌ها',
            'tree'        => $this->subjects->tree(),
            'depthLabels' => QbSubjectRepository::DEPTH_LABELS,
            'maxDepth'    => QbSubjectRepository::MAX_DEPTH,
        ]);
    }

    public function storeSubject(Request $request, array $params = []): Response
    {
        $parentUuid = $request->string('parent');
        $parentId   = null;

        if ($parentUuid !== '') {
            $parent = $this->subjects->findByUuid($parentUuid);
            if ($parent === null) {
                $this->flash('error', 'سرشاخه انتخاب‌شده پیدا نشد.');
                return $this->redirect('/admin/qbank/subjects');
            }
            $parentId = (int) $parent['id'];
        }

        try {
            $id = $this->subjects->create($parentId, [
                'title'       => mb_substr($request->string('title'), 0, 191),
                'description' => mb_substr($request->string('description'), 0, 255),
                'sort_order'  => $request->int('sort_order'),
            ], Auth::id());
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/qbank/subjects');
        }

        ActivityLogger::log('qbank.subject.created', Auth::id(), 'qb_subject', $id, [], 'info', $request);

        // Access is granted per درس, so only a top-level one is handed to the
        // students holding a full-access package.
        if ($parentId === null) {
            \HeleXa\Services\PackageAccess::contentAdded('qbank_subject', $id, Auth::id());
        }

        $this->flash('success', 'افزوده شد.');

        return $this->redirect('/admin/qbank/subjects');
    }

    public function updateSubject(Request $request, array $params = []): Response
    {
        $row = $this->subjectOr404((string) ($params['uuid'] ?? ''));

        try {
            $this->subjects->update((int) $row['id'], [
                'title'       => mb_substr($request->string('title'), 0, 191),
                'description' => mb_substr($request->string('description'), 0, 255),
                'sort_order'  => $request->int('sort_order'),
                'is_active'   => $request->bool('is_active'),
            ]);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/qbank/subjects');
        }

        ActivityLogger::log('qbank.subject.updated', Auth::id(), 'qb_subject', (int) $row['id'], [], 'info', $request);
        $this->flash('success', 'ذخیره شد.');

        return $this->redirect('/admin/qbank/subjects');
    }

    /** The درسنامه editor for one درس / زیردرس / عنوان. */
    public function lessonNote(Request $request, array $params = []): Response
    {
        $row = $this->subjectOr404((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.qbank.lesson_note', [
            'title'   => 'درسنامه — ' . $row['title'],
            'subject' => $row,
            'preview' => \HeleXa\Services\QuestionBank\LessonNotes::html((string) ($row['lesson_note'] ?? '')),
        ]);
    }

    public function saveLessonNote(Request $request, array $params = []): Response
    {
        $row = $this->subjectOr404((string) ($params['uuid'] ?? ''));
        $this->subjects->setLessonNote((int) $row['id'], (string) $request->input('lesson_note', ''));

        ActivityLogger::log('qbank.subject.lesson_note', Auth::id(), 'qb_subject', (int) $row['id'], [], 'info', $request);
        $this->flash('success', 'درسنامه ذخیره شد.');

        return $this->redirect('/admin/qbank/subjects/' . $row['uuid'] . '/lesson');
    }

    public function destroySubject(Request $request, array $params = []): Response
    {
        $row      = $this->subjectOr404((string) ($params['uuid'] ?? ''));
        $orphaned = $this->subjects->questionCount((int) $row['id']);

        $this->subjects->delete((int) $row['id']);

        ActivityLogger::log('qbank.subject.deleted', Auth::id(), 'qb_subject', (int) $row['id'],
            ['title' => $row['title'], 'orphaned_questions' => $orphaned], 'warning', $request);

        // Said out loud, because the questions are kept but lose their filing,
        // and an admin who expected them to go with the subject should know
        // where to find them.
        $this->flash('success', $orphaned > 0
            ? '«' . $row['title'] . '» حذف شد. ' . fa((string) $orphaned)
              . ' سوال مرتبط حذف نشدند و در فیلتر «بدون طبقه‌بندی» قرار گرفتند.'
            : '«' . $row['title'] . '» حذف شد.');

        return $this->redirect('/admin/qbank/subjects');
    }

    /**
     * The children of one row, as JSON, for the cascading selects on the
     * question form. Inactive rows are left out: they can still hold existing
     * questions, but new filing into them is what deactivating is for.
     */
    public function subjectChildren(Request $request, array $params = []): Response
    {
        $uuid = (string) ($params['uuid'] ?? '');
        $row  = $this->subjects->findByUuid($uuid);

        if ($row === null) {
            return $this->json(['ok' => false, 'items' => []], 404);
        }

        $items = array_map(static fn (array $child): array => [
            'id'    => (int) $child['id'],
            'uuid'  => $child['uuid'],
            'title' => $child['title'],
        ], $this->subjects->children((int) $row['id'], true));

        return $this->json(['ok' => true, 'items' => $items]);
    }

    /* -------------------------------------------------------------- tags */

    public function tags(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.qbank.tags', [
            'title'  => 'برچسب‌های سوال',
            'tags'   => $this->tags->all(),
            'colors' => QbTagRepository::COLORS,
        ]);
    }

    public function storeTag(Request $request, array $params = []): Response
    {
        try {
            $id = $this->tags->create(
                mb_substr($request->string('title'), 0, 96),
                $request->string('color'),
                $request->int('sort_order')
            );
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/qbank/tags');
        }

        ActivityLogger::log('qbank.tag.created', Auth::id(), 'qb_tag', $id, [], 'info', $request);
        $this->flash('success', 'برچسب افزوده شد.');

        return $this->redirect('/admin/qbank/tags');
    }

    public function updateTag(Request $request, array $params = []): Response
    {
        $tag = $this->tagOr404((int) ($params['id'] ?? 0));

        try {
            $this->tags->update(
                (int) $tag['id'],
                mb_substr($request->string('title'), 0, 96),
                $request->string('color'),
                $request->int('sort_order'),
                $request->bool('is_active')
            );
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/qbank/tags');
        }

        $this->flash('success', 'برچسب ذخیره شد.');
        return $this->redirect('/admin/qbank/tags');
    }

    public function destroyTag(Request $request, array $params = []): Response
    {
        $tag = $this->tagOr404((int) ($params['id'] ?? 0));
        $this->tags->delete((int) $tag['id']);

        ActivityLogger::log('qbank.tag.deleted', Auth::id(), 'qb_tag', (int) $tag['id'],
            ['title' => $tag['title']], 'notice', $request);
        $this->flash('success', 'برچسب «' . $tag['title'] . '» حذف شد. سوال‌ها دست‌نخورده ماندند.');

        return $this->redirect('/admin/qbank/tags');
    }

    /* ----------------------------------------------------------- helpers */

    private function subjectOr404(string $uuid): array
    {
        $row = $this->subjects->findByUuid($uuid);
        if ($row === null) {
            throw HttpException::notFound();
        }
        return $row;
    }

    private function tagOr404(int $id): array
    {
        $tag = $this->tags->find($id);
        if ($tag === null) {
            throw HttpException::notFound();
        }
        return $tag;
    }

    /** Every page of the bank carries its own stylesheet and script. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
