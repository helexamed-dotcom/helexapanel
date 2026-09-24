<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\QuestionBank;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\QuestionBank\QbImageStorage;

/**
 * Writing, editing and publishing questions.
 *
 * Every text field on a question — the stem, each option, the explanation —
 * may be typed, uploaded as an image, or pasted from the clipboard as an
 * image, in any combination. The form sends all three channels for each
 * field and resolveImage() decides which one wins:
 *
 *     remove ticked      → no image
 *     pasted data URL    → store the pasted bytes
 *     file chosen        → store the upload
 *     otherwise          → keep what the question already had
 *
 * New images are stored before the database write and cleaned up if that
 * write fails; replaced images are removed only after it succeeds. The order
 * matters: the reverse would leave a saved question pointing at a deleted
 * file whenever the save was refused.
 */
final class QuestionController extends Controller
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 8;
    private const MAX_TEXT   = 8000;
    private const PER_PAGE   = 30;
    private const BULK_LIMIT = 300;

    private QbQuestionRepository $questions;
    private QbSubjectRepository $subjects;
    private QbTagRepository $tags;

    /** Files written during this request, so a failed save can remove them. */
    private array $freshImages = [];

    public function __construct()
    {
        $this->questions = new QbQuestionRepository();
        $this->subjects  = new QbSubjectRepository();
        $this->tags      = new QbTagRepository();
    }

    /* -------------------------------------------------------------- list */

    public function index(Request $request, array $params = []): Response
    {
        // The filter row sends the deepest pick as subject_id; without
        // JavaScript only its three selects (f1 درس, f2 زیردرس, f3 عنوان)
        // arrive, and the deepest one of those is used instead.
        $subjectFilter = $request->string('subject_id');
        if ($subjectFilter === '') {
            foreach (['f3', 'f2', 'f1'] as $level) {
                if ($request->string($level) !== '') {
                    $subjectFilter = $request->string($level);
                    break;
                }
            }
        }

        $filters = [
            'q'          => trim($request->string('q')),
            'subject_id' => $subjectFilter,
            'difficulty' => $request->string('difficulty'),
            'status'     => $request->string('status'),
            'tag_id'     => $request->int('tag_id'),
            // More rows per page makes bulk actions reach further.
            'per_page'   => in_array($request->int('per_page'), [30, 100, 300], true) ? $request->int('per_page') : self::PER_PAGE,
        ];

        $perPage = (int) $filters['per_page'];
        $page    = max(1, $request->int('page', 1));
        $total   = $this->questions->countMatching($filters);
        $rows    = $this->questions->search($filters, $perPage, ($page - 1) * $perPage);

        return $this->page('layouts.app', 'admin.qbank.questions.index', [
            'title'       => 'سوالات',
            'questions'   => $rows,
            'tagsByQuestion' => $this->questions->tagsForMany(array_column($rows, 'id')),
            'filters'     => $filters,
            'tree'        => $this->subjects->tree(),
            'tags'        => $this->tags->all(),
            'difficulties'=> QbQuestionRepository::DIFFICULTY_LABELS,
            'page'        => $page,
            'pages'       => max(1, (int) ceil($total / $perPage)),
            'total'       => $total,
        ]);
    }

    /* -------------------------------------------------------------- form */

    public function create(Request $request, array $params = []): Response
    {
        return $this->form($request, null, [], []);
    }

    public function edit(Request $request, array $params = []): Response
    {
        $question = $this->questionOr404((string) ($params['uuid'] ?? ''));

        return $this->form(
            $request,
            $question,
            $this->questions->optionsFor((int) $question['id']),
            array_map(static fn (array $t): int => (int) $t['id'], $this->questions->tagsFor((int) $question['id']))
        );
    }

    /**
     * A read-only rendering, exactly as a student would see it after answering
     * — with the correct option marked and the explanation shown.
     */
    public function preview(Request $request, array $params = []): Response
    {
        $question = $this->questionOr404((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.qbank.questions.preview', [
            'title'        => 'پیش‌نمایش سوال',
            'question'     => $question,
            'options'      => $this->questions->optionsFor((int) $question['id']),
            'tags'         => $this->questions->tagsFor((int) $question['id']),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request, array $params = []): Response
    {
        try {
            [$data, $options, $tagIds] = $this->collect($request, null, []);

            $uuid = $this->questions->create($data, $options, $tagIds, Auth::id());
            $created = $this->questions->findByUuid($uuid);
            if ($created !== null) {
                $this->questions->setLessonNote((int) $created['id'], (string) $request->input('lesson_note', ''));
                $this->syncLessons((int) $created['id'], $request);
            }
        } catch (\RuntimeException $e) {
            $this->discardFreshImages();
            $this->flash('error', $e->getMessage());

            return $this->redirect('/admin/qbank/questions/create');
        }

        ActivityLogger::log('qbank.question.created', Auth::id(), 'qb_question', null,
            ['uuid' => $uuid, 'status' => $data['status']], 'info', $request);

        $this->flash('success', 'سوال ذخیره شد.');

        // "Save and add another" keeps the filing, which is how a bank is
        // actually written: thirty questions in a row under the same topic.
        if ($request->bool('add_another')) {
            return $this->redirect('/admin/qbank/questions/create?' . http_build_query(array_filter([
                'subject'     => $data['subject_id'],
                'sub_subject' => $data['sub_subject_id'],
                'topic'       => $data['topic_id'],
                'difficulty'  => $data['difficulty'],
            ])));
        }

        return $this->redirect('/admin/qbank/questions');
    }

    public function update(Request $request, array $params = []): Response
    {
        $question = $this->questionOr404((string) ($params['uuid'] ?? ''));
        $id       = (int) $question['id'];
        $existing = $this->questions->optionsFor($id);
        $before   = $this->questions->imageNames($id);

        try {
            [$data, $options, $tagIds] = $this->collect($request, $question, $existing);

            $this->questions->update($id, $request->int('version'), $data, $options, $tagIds);
            $this->questions->setLessonNote($id, (string) $request->input('lesson_note', ''));
            $this->syncLessons($id, $request);
        } catch (\RuntimeException $e) {
            $this->discardFreshImages();
            $this->flash('error', $e->getMessage());

            return $this->redirect('/admin/qbank/questions/' . $question['uuid'] . '/edit');
        }

        // Only now, with the new row committed, are superseded files removed.
        $after = $this->questions->imageNames($id);
        foreach (array_diff($before, $after) as $stale) {
            QbImageStorage::forget($stale);
        }

        ActivityLogger::log('qbank.question.updated', Auth::id(), 'qb_question', $id, [], 'info', $request);
        $this->flash('success', 'تغییرات سوال ذخیره شد.');

        return $this->redirect('/admin/qbank/questions/' . $question['uuid'] . '/edit');
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $question = $this->questionOr404((string) ($params['uuid'] ?? ''));
        $status   = $request->string('status') === 'published' ? 'published' : 'draft';

        if ($status === 'published') {
            $problem = $this->publishProblem($question, $this->questions->optionsFor((int) $question['id']));
            if ($problem !== null) {
                $this->flash('error', $problem);
                return $this->redirect($this->backToList($request));
            }
        }

        $this->questions->setStatus((int) $question['id'], $status);

        ActivityLogger::log('qbank.question.status', Auth::id(), 'qb_question', (int) $question['id'],
            ['status' => $status], 'notice', $request);
        $this->flash('success', $status === 'published' ? 'سوال منتشر شد.' : 'سوال به پیش‌نویس برگشت.');

        return $this->redirect($this->backToList($request));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $question = $this->questionOr404((string) ($params['uuid'] ?? ''));

        // Soft delete, and the images stay: a question restored from the
        // database by hand should come back whole.
        $this->questions->softDelete((int) $question['id']);

        ActivityLogger::log('qbank.question.deleted', Auth::id(), 'qb_question', (int) $question['id'],
            [], 'warning', $request);
        $this->flash('success', 'سوال حذف شد.');

        return $this->redirect($this->backToList($request));
    }

    /**
     * Publish, unpublish or delete many questions at once.
     *
     * Each action keeps the permission its single-question button needs, and
     * publishing still runs the same readiness check per question: the ones
     * that are not ready are skipped and counted rather than published.
     */
    public function bulk(Request $request, array $params = []): Response
    {
        $back   = $this->backToList($request);
        $action = $request->string('action');
        $needs  = [
            'publish' => 'qbank.publish',
            'draft'   => 'qbank.publish',
            'delete'  => 'qbank.manage_questions',
        ];

        if (!isset($needs[$action])) {
            $this->flash('error', 'عملیات گروهی را انتخاب کنید.');
            return $this->redirect($back);
        }
        if (!Auth::can($needs[$action])) {
            throw HttpException::forbidden();
        }

        $raw   = $request->input('ids', []);
        $uuids = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
                $uuids[] = strtolower($value);
            }
        }
        $uuids = array_slice(array_values(array_unique($uuids)), 0, self::BULK_LIMIT);

        $rows = $this->questions->findManyByUuids($uuids);
        if ($rows === []) {
            $this->flash('error', 'هیچ سوالی انتخاب نشده است.');
            return $this->redirect($back);
        }

        $done    = 0;
        $skipped = 0;
        $options = $action === 'publish'
            ? $this->questions->optionsForMany(array_map(static fn (array $r): int => (int) $r['id'], $rows))
            : [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($action === 'delete') {
                $this->questions->softDelete($id);
                $done++;
                continue;
            }

            $target = $action === 'publish' ? 'published' : 'draft';
            if ($target === 'published' && $this->publishProblem($row, $options[$id] ?? []) !== null) {
                $skipped++;
                continue;
            }
            if ($row['status'] !== $target) {
                $this->questions->setStatus($id, $target);
            }
            $done++;
        }

        ActivityLogger::log('qbank.question.bulk', Auth::id(), 'qb_question', null, [
            'action' => $action, 'done' => $done, 'skipped' => $skipped,
        ], $action === 'delete' ? 'warning' : 'notice', $request);

        $verb = ['publish' => 'منتشر شد', 'draft' => 'به پیش‌نویس برگشت', 'delete' => 'حذف شد'][$action];
        $this->flash('success', fa((string) $done) . ' سوال ' . $verb . '.');
        if ($skipped > 0) {
            $this->flash('error', fa((string) $skipped) . ' سوال منتشر نشد، چون آماده انتشار نبود '
                . '(درس ندارد یا دقیقاً یک گزینه صحیح ندارد).');
        }

        return $this->redirect($back);
    }

    /**
     * Streams a question image to an admin.
     *
     * Students have their own route, which additionally checks that the image
     * belongs to a question in a درس they hold. This one only needs the
     * qbank.view permission the route group already enforces.
     */
    public function image(Request $request, array $params = []): Response
    {
        return self::streamImage((string) ($params['name'] ?? ''));
    }

    public static function streamImage(string $name): Response
    {
        $resolved = QbImageStorage::resolve($name);
        if ($resolved === null) {
            throw HttpException::notFound();
        }

        $bytes = (string) file_get_contents($resolved['path']);

        return Response::make($bytes, 200, [
            'Content-Type'           => $resolved['mime'],
            'Content-Length'         => (string) strlen($bytes),
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }

    /* ----------------------------------------------------------- helpers */

    private function form(Request $request, ?array $question, array $options, array $tagIds): Response
    {
        // Pre-fill from "save and add another" on a fresh form.
        $prefill = $question !== null
            ? [
                'subject_id'     => $question['subject_id'],
                'sub_subject_id' => $question['sub_subject_id'],
                'topic_id'       => $question['topic_id'],
                'difficulty'     => $question['difficulty'],
            ]
            : [
                'subject_id'     => $request->int('subject') ?: null,
                'sub_subject_id' => $request->int('sub_subject') ?: null,
                'topic_id'       => $request->int('topic') ?: null,
                'difficulty'     => $request->string('difficulty', 'medium'),
            ];

        if (!in_array($prefill['difficulty'], QbQuestionRepository::DIFFICULTIES, true)) {
            $prefill['difficulty'] = 'medium';
        }

        // Four empty rows on a new question: the common case is a four-choice
        // MCQ, and adding rows is one click away for the rest.
        if ($options === []) {
            $options = array_fill(0, 4, ['body_text' => '', 'body_image' => null, 'is_correct' => 0, 'uuid' => '']);
        }

        return $this->page('layouts.app', 'admin.qbank.questions.form', [
            'title'        => $question === null ? 'سوال جدید' : 'ویرایش سوال',
            'question'     => $question,
            'options'      => $options,
            'tagIds'       => $tagIds,
            'prefill'      => $prefill,
            'tree'         => $this->subjects->tree(),
            'tags'         => $this->tags->all(true),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
            'minOptions'   => self::MIN_OPTIONS,
            'maxOptions'   => self::MAX_OPTIONS,
            'maxImageKb'   => (int) (QbImageStorage::maxBytes() / 1024),
            'lessonOptions'=> $this->lessonOptions(),
            'lessonIds'    => $question === null || !\HeleXa\Models\LessonRepository::ready() ? []
                : (new \HeleXa\Models\LessonRepository())->lessonIdsForQuestion((int) $question['id']),
        ]);
    }

    /** «درسنامه‌های مرتبط»: the درسنامه‌ها a wrong answer should send the student to. */
    private function syncLessons(int $questionId, Request $request): void
    {
        if (!\HeleXa\Models\LessonRepository::ready()) {
            return;
        }
        $ids = $request->input('lessons', []);
        (new \HeleXa\Models\LessonRepository())->syncQuestionLessons($questionId, is_array($ids) ? $ids : []);
    }

    private function lessonOptions(): array
    {
        if (!\HeleXa\Models\LessonRepository::ready()) {
            return [];
        }
        return (new \HeleXa\Models\LessonRepository())->search([], false, 500);
    }

    /**
     * Reads and validates the whole form.
     *
     * @return array{0:array<string,mixed>, 1:array<int,array<string,mixed>>, 2:array<int,int>}
     * @throws \RuntimeException with a message fit to show the admin
     */
    private function collect(Request $request, ?array $question, array $existingOptions): array
    {
        [$subjectId, $subId, $topicId] = $this->resolveFiling($request);

        $difficulty = $request->string('difficulty');
        if (!in_array($difficulty, QbQuestionRepository::DIFFICULTIES, true)) {
            throw new \RuntimeException('درجه سختی معتبر نیست.');
        }

        $stemText  = $this->text($request->input('stem_text'));
        $stemImage = $this->resolveImage($request, 'stem', $question['stem_image'] ?? null);

        if ($stemText === null && $stemImage === null) {
            throw new \RuntimeException('صورت سوال خالی است. متنی بنویس یا تصویری قرار بده.');
        }

        $explanationText  = $this->text($request->input('explanation_text'));
        $explanationImage = $this->resolveImage($request, 'explanation', $question['explanation_image'] ?? null);

        // Existing options are looked up by uuid, not by position: rows can be
        // removed from the middle of the form, and matching by index would
        // hand option C's image to what used to be option D.
        $existingByUuid = [];
        foreach ($existingOptions as $row) {
            $existingByUuid[$row['uuid']] = $row;
        }

        $rawOptions = $request->input('options');
        $rawOptions = is_array($rawOptions) ? array_values($rawOptions) : [];
        $correctKey = (string) $request->input('correct', '');

        $options = [];
        foreach (array_slice($rawOptions, 0, self::MAX_OPTIONS) as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            // The key names this row's file input, so it is reduced to characters
            // that are safe inside a form field name.
            $key      = preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['key'] ?? $index)) ?: (string) $index;
            $prior    = $existingByUuid[(string) ($raw['uuid'] ?? '')] ?? null;
            $bodyText = $this->text($raw['text'] ?? null);
            $image    = $this->resolveImage($request, 'option_' . $key, $prior['body_image'] ?? null, $raw);

            // A row with neither text nor image is an unused slot, not an
            // error — the form always shows a few spare rows.
            if ($bodyText === null && $image === null) {
                continue;
            }

            $options[] = [
                'body_text'  => $bodyText,
                'body_image' => $image,
                'is_correct' => $correctKey !== '' && $correctKey === $key,
            ];
        }

        if (count($options) < self::MIN_OPTIONS) {
            throw new \RuntimeException('دست‌کم ' . fa((string) self::MIN_OPTIONS) . ' گزینه لازم است.');
        }

        // Writing and publishing are separate permissions. Without this, the
        // save form would be a second door to publishing that the status
        // route's permission check never sees. An editor without the right
        // keeps an already-published question published, but cannot
        // publish a draft.
        $status = $request->string('status') === 'published' ? 'published' : 'draft';
        if ($status === 'published' && !Auth::can('qbank.publish')
            && ($question === null || $question['status'] !== 'published')) {
            $status = 'draft';
        }
        $data   = [
            'subject_id'        => $subjectId,
            'sub_subject_id'    => $subId,
            'topic_id'          => $topicId,
            'stem_text'         => $stemText,
            'stem_image'        => $stemImage,
            'difficulty'        => $difficulty,
            'explanation_text'  => $explanationText,
            'explanation_image' => $explanationImage,
            'status'            => $status,
        ];

        if ($status === 'published') {
            $problem = $this->publishProblem($data, $options);
            if ($problem !== null) {
                throw new \RuntimeException($problem . ' می‌توانی آن را به‌صورت پیش‌نویس ذخیره کنی.');
            }
        }

        $tagIds = $request->input('tags');
        $tagIds = is_array($tagIds) ? array_map('intval', $tagIds) : [];

        return [$data, $options, $tagIds];
    }

    /**
     * The three filing levels, each optional, checked against each other.
     *
     * The cascading selects make an inconsistent choice impossible in the
     * browser, but the server does not rely on the browser: a زیردرس must
     * actually sit under the chosen درس, and a عنوان under the chosen زیردرس.
     * A deeper level chosen without the level above it is accepted and the
     * missing parent is filled in from the tree, so filing always reads as a
     * complete path.
     *
     * @return array{0:?int, 1:?int, 2:?int}
     */
    private function resolveFiling(Request $request): array
    {
        $pick = function (string $field, int $depth) use ($request): ?int {
            $id = $request->int($field);
            if ($id <= 0) {
                return null;
            }
            $row = $this->subjects->find($id);
            if ($row === null || (int) $row['depth'] !== $depth) {
                throw new \RuntimeException('طبقه‌بندی انتخاب‌شده معتبر نیست.');
            }
            return $id;
        };

        $subjectId = $pick('subject_id', 1);
        $subId     = $pick('sub_subject_id', 2);
        $topicId   = $pick('topic_id', 3);

        if ($topicId !== null) {
            $topicParent = (int) $this->subjects->find($topicId)['parent_id'];
            if ($subId === null) {
                $subId = $topicParent;
            } elseif ($subId !== $topicParent) {
                throw new \RuntimeException('عنوان انتخاب‌شده زیر این زیردرس نیست.');
            }
        }

        if ($subId !== null) {
            $subParent = (int) $this->subjects->find($subId)['parent_id'];
            if ($subjectId === null) {
                $subjectId = $subParent;
            } elseif ($subjectId !== $subParent) {
                throw new \RuntimeException('زیردرس انتخاب‌شده زیر این درس نیست.');
            }
        }

        return [$subjectId, $subId, $topicId];
    }

    /**
     * Decides the image for one field. See the class comment for the order.
     *
     * Option fields carry their paste/keep/remove values inside the options
     * array rather than as top-level fields, so they are passed in as $raw.
     */
    private function resolveImage(Request $request, string $prefix, ?string $current, ?array $raw = null): ?string
    {
        $get = static function (string $key) use ($request, $prefix, $raw): string {
            if ($raw !== null) {
                $value = $raw[$key] ?? '';
            } else {
                $value = $request->input($prefix . '_' . $key, '');
            }
            return is_string($value) ? $value : '';
        };

        if ($get('image_remove') === '1') {
            return null;
        }

        $pasted = $get('image_data');
        if ($pasted !== '') {
            $bytes = QbImageStorage::decodeDataUrl($pasted);
            if ($bytes === null) {
                throw new \RuntimeException('تصویر چسبانده‌شده قابل خواندن نیست.');
            }
            return $this->remember(QbImageStorage::storeBlob($bytes));
        }

        $upload = $request->file($prefix . '_image_file');
        if ($upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return $this->remember(QbImageStorage::store($upload));
        }

        return $current !== null && $current !== '' ? $current : null;
    }

    private function remember(string $name): string
    {
        $this->freshImages[] = $name;
        return $name;
    }

    private function discardFreshImages(): void
    {
        foreach ($this->freshImages as $name) {
            QbImageStorage::forget($name);
        }
        $this->freshImages = [];
    }

    /**
     * Why a question is not ready to publish, or null if it is.
     *
     * A draft may be incomplete — that is what drafts are for. A published
     * question reaches students, and one with no correct answer marked would
     * mark every student wrong.
     */
    private function publishProblem(array $question, array $options): ?string
    {
        $correct = 0;
        foreach ($options as $option) {
            if (!empty($option['is_correct'])) {
                $correct++;
            }
        }

        if (count($options) < self::MIN_OPTIONS) {
            return 'سوال منتشرشده دست‌کم ' . fa((string) self::MIN_OPTIONS) . ' گزینه لازم دارد.';
        }
        if ($correct !== 1) {
            return 'برای انتشار، دقیقاً یک گزینه باید به‌عنوان پاسخ صحیح علامت خورده باشد.';
        }
        if (empty($question['subject_id'])) {
            return 'سوال بدون درس منتشر نمی‌شود، چون دسترسی دانشجو بر اساس درس است و چنین سوالی به هیچ‌کس نمی‌رسد.';
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    private function questionOr404(string $uuid): array
    {
        $question = $this->questions->findByUuid($uuid);
        if ($question === null) {
            throw HttpException::notFound();
        }
        return $question;
    }

    /** Returns to the filtered list the action was taken from, never off-site. */
    private function backToList(Request $request): string
    {
        $back = $request->string('back');

        return str_starts_with($back, '/admin/qbank/questions') && !str_contains($back, '//')
            ? $back
            : '/admin/qbank/questions';
    }

    /** Every page of the bank carries its own stylesheet and script. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
