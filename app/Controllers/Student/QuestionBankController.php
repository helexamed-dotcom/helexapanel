<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Controllers\Admin\QuestionBank\QuestionController as AdminQuestions;
use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbPracticeRepository;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbReportRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\QuestionBank\QbAccess;
use HeleXa\Services\QuestionBank\LessonNotes;
use HeleXa\Services\QuestionBank\QbXp;

/**
 * The student's question bank: pick a درس, drill its questions one at a time.
 *
 * Grading happens on the server. The page that shows a question does not
 * carry which option is correct — it is returned by answer(), after the
 * student has committed, together with the explanation.
 */
final class QuestionBankController extends Controller
{
    private const LIST_SIZE = 25;

    private QbPracticeRepository $practice;

    public function __construct()
    {
        $this->practice = new QbPracticeRepository();
    }

    /** The درس list, or the closed page. */
    public function index(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $decision = QbAccess::forStudent($userId);

        if (!$decision['allowed']) {
            return $this->page('layouts.app', 'student.qbank.gate', [
                'title'    => 'بانک سوال',
                'decision' => $decision,
            ]);
        }

        $subjects = (new QbAccessRepository())->subjectsFor($userId);
        $stats    = [];
        foreach ($subjects as $subject) {
            $stats[(int) $subject['id']] = $this->practice->statsFor($userId, (int) $subject['id']);
        }

        return $this->page('layouts.app', 'student.qbank.index', [
            'title'    => 'بانک سوال',
            'subjects' => $subjects,
            'stats'    => $stats,
        ]);
    }

    /**
     * One question from the filtered sequence of one درس.
     *
     * Opened bare — straight from the درس card — the درس's own page comes
     * first: its زیردرس‌ها and عنوان‌ها with progress, so the student picks
     * where to practise instead of landing in question one of everything.
     * Any query (a position, a filter, a mode) goes to the player.
     */
    public function practice(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $subject = $this->grantedSubjectOr404($userId, (string) ($params['uuid'] ?? ''));

        if ($request->all() === []) {
            return $this->page('layouts.app', 'student.qbank.subject', [
                'title'   => $subject['title'],
                'subject' => $subject,
                'outline' => $this->practice->outline($userId, (int) $subject['id']),
                'marks'   => $this->markCounts($userId, (int) $subject['id']),
                'lesson'  => trim((string) ($subject['lesson_note'] ?? '')) !== '',
            ]);
        }

        $filters = $this->readFilters($request);

        $total    = $this->practice->count($userId, (int) $subject['id'], $filters);
        $position = max(0, min($request->int('n', 1) - 1, max(0, $total - 1)));
        $question = $total > 0 ? $this->practice->at($userId, (int) $subject['id'], $filters, $position) : null;

        return $this->page('layouts.app', 'student.qbank.practice', [
            'title'        => $subject['title'],
            'subject'      => $subject,
            'question'     => $question,
            'options'      => $question === null ? [] : $this->practice->optionsWithoutKey((int) $question['id']),
            'tags'         => $question === null ? [] : (new QbQuestionRepository())->tagsFor((int) $question['id']),
            'position'     => $position + 1,
            'total'        => $total,
            'filters'      => $filters,
            'filing'       => $this->practice->filingOptions((int) $subject['id']),
            'allTags'      => (new QbTagRepository())->all(true),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
            'stats'        => $this->practice->statsFor($userId, (int) $subject['id']),
            'last'         => $question === null ? null : $this->practice->lastAttempt($userId, (int) $question['id']),
            'marks'        => $question === null ? [] : $this->practice->marksFor($userId, (int) $question['id']),
        ]);
    }

    /**
     * The same filtered sequence as a searchable list.
     *
     * Each row links into the player at its own position, with the same
     * filters, so "find it in the list, then practise from there" lands on the
     * exact question and carries on through the same set.
     */
    public function listing(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $subject = $this->grantedSubjectOr404($userId, (string) ($params['uuid'] ?? ''));
        $filters = $this->readFilters($request);
        $page    = max(1, $request->int('page', 1));
        $total   = $this->practice->count($userId, (int) $subject['id'], $filters);

        return $this->page('layouts.app', 'student.qbank.list', [
            'title'        => $subject['title'] . ' — فهرست سوال‌ها',
            'subject'      => $subject,
            'rows'         => $this->practice->page($userId, (int) $subject['id'], $filters, self::LIST_SIZE, ($page - 1) * self::LIST_SIZE),
            'filters'      => $filters,
            'page'         => $page,
            'pages'        => max(1, (int) ceil($total / self::LIST_SIZE)),
            'total'        => $total,
            'offset'       => ($page - 1) * self::LIST_SIZE,
            'filing'       => $this->practice->filingOptions((int) $subject['id']),
            'allTags'      => (new QbTagRepository())->all(true),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
        ]);
    }

    /** Toggles «نشان‌شده» or «نیاز به مرور» on one question. */
    public function mark(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $question = $this->practice->findPublished((string) ($params['uuid'] ?? ''));
        $mark     = $request->string('mark');

        // 'exam' files the question into «آزمون‌های من».
        if (!in_array($mark, ['saved', 'review', 'exam'], true)) {
            return $this->json(['ok' => false, 'message' => 'نشان نامعتبر است.'], 422);
        }
        if ($question === null
            || $question['subject_id'] === null
            || !QbAccess::allowsSubject($userId, (int) $question['subject_id'])) {
            return $this->json(['ok' => false, 'message' => 'این سوال در دسترس نیست.'], 404);
        }

        return $this->json([
            'ok'   => true,
            'mark' => $mark,
            'on'   => $this->practice->toggleMark($userId, (int) $question['id'], $mark),
        ]);
    }

    /**
     * Grades one answer and returns the key and the explanation.
     *
     * Access is checked against the question's own درس, looked up from the
     * question — the request does not say which درس it belongs to, so there
     * is nothing to tamper with.
     */
    public function answer(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $question = $this->practice->findPublished((string) ($params['uuid'] ?? ''));

        if ($question === null
            || $question['subject_id'] === null
            || !QbAccess::allowsSubject($userId, (int) $question['subject_id'])) {
            return $this->json(['ok' => false, 'message' => 'این سوال در دسترس نیست.'], 404);
        }

        $chosenUuid = $request->string('option');
        $options    = $this->practice->optionsWithKey((int) $question['id']);
        $chosen     = null;
        $correct    = null;

        foreach ($options as $option) {
            if ($option['uuid'] === $chosenUuid) {
                $chosen = $option;
            }
            if ((int) $option['is_correct'] === 1) {
                $correct = $option;
            }
        }

        if ($chosen === null) {
            return $this->json(['ok' => false, 'message' => 'گزینه انتخاب‌شده معتبر نیست.'], 422);
        }

        $isCorrect    = (int) $chosen['is_correct'] === 1;
        $firstAttempt = !$this->practice->hasAttempted($userId, (int) $question['id']);

        $this->practice->recordAttempt(
            $userId,
            (int) $question['id'],
            (int) $chosen['id'],
            (int) $question['subject_id'],
            $isCorrect
        );

        // XP in the shared Balin ledger: first try only, once per question.
        $reward = $isCorrect && $firstAttempt
            ? QbXp::award($userId, (int) $question['id'], (string) $question['difficulty'])
            : null;

        return $this->json([
            'ok'                => true,
            'correct'           => $isCorrect,
            'xp'                => $reward,
            'correct_option'    => $correct['uuid'] ?? null,
            'explanation_text'  => (string) ($question['explanation_text'] ?? ''),
            'explanation_image' => !empty($question['explanation_image'])
                ? '/student/qbank/image/' . rawurlencode((string) $question['explanation_image'])
                : null,
            // Returned only now, after the student has committed: seeing how
            // the class split before answering would be a hint.
            'distribution'      => $this->practice->distribution((int) $question['id']),
        ]);
    }

    /**
     * «گزارش اشکال»: a student flags a question for the admin.
     */
    public function report(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $question = $this->practice->findPublished((string) ($params['uuid'] ?? ''));

        if ($question === null
            || $question['subject_id'] === null
            || !QbAccess::allowsSubject($userId, (int) $question['subject_id'])) {
            return $this->json(['ok' => false, 'message' => 'این سوال در دسترس نیست.'], 404);
        }

        $body = $request->string('body');
        if (mb_strlen(trim($body)) < 3 && $request->string('reason') === 'other') {
            return $this->json(['ok' => false, 'message' => 'لطفاً توضیح کوتاهی بنویس که مشکل چیست.'], 422);
        }

        (new QbReportRepository())->submit($userId, (int) $question['id'], $request->string('reason'), $body);

        return $this->json(['ok' => true, 'message' => 'گزارش شما ثبت شد. ممنون که کمک می‌کنی بانک سوال بهتر شود 🙏']);
    }

    /**
     * «درسنامه»: the lesson behind a question, after the student has answered
     * it — before that it would be a hint.
     */
    public function lessonNote(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $question = $this->practice->findPublished((string) ($params['uuid'] ?? ''));

        if ($question === null
            || $question['subject_id'] === null
            || !QbAccess::allowsSubject($userId, (int) $question['subject_id'])) {
            return $this->json(['ok' => false, 'message' => 'این سوال در دسترس نیست.'], 404);
        }
        if (!$this->practice->hasSeenAnswer($userId, (int) $question['id'])) {
            return $this->json(['ok' => false, 'message' => 'درسنامه بعد از پاسخ دادن به سوال باز می‌شود.'], 403);
        }

        $note = LessonNotes::forQuestion($question);

        return $this->json($note === null
            ? ['ok' => true, 'found' => false, 'message' => 'برای این درس درسنامه‌ای وجود ندارد.']
            : ['ok' => true, 'found' => true, 'title' => $note['title'], 'html' => $note['html']]);
    }

    /**
     * Streams a question image to a student.
     *
     * Served only when the file belongs to a published question in a درس the
     * student holds. An explanation image is served only after the student
     * has answered that question at least once — otherwise its filename,
     * visible to anyone who has seen the answer, would let a student read the
     * explanation before attempting.
     */
    public function image(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $name   = (string) ($params['name'] ?? '');
        $owner  = $this->practice->ownerOfImage($name);

        if ($owner === null
            || $owner['subject_id'] === null
            || !QbAccess::allowsSubject($userId, $owner['subject_id'])) {
            throw HttpException::notFound();
        }

        if ($owner['part'] === 'explanation' && !$this->practice->hasSeenAnswer($userId, $owner['question_id'])) {
            throw HttpException::notFound();
        }

        return AdminQuestions::streamImage($name);
    }

    /**
     * The filter set shared by the player and the list, read the same way in
     * both so a link from one to the other lands on the same sequence.
     */
    /** @return array{saved:int, review:int} */
    private function markCounts(int $userId, int $subjectId): array
    {
        $out = ['saved' => 0, 'review' => 0];
        try {
            foreach (\HeleXa\Core\Database::select(
                'SELECT m.mark, COUNT(*) AS c FROM qb_marks m JOIN qb_questions q ON q.id = m.question_id
                 WHERE m.user_id = :u AND q.subject_id = :s AND m.mark IN (\'saved\', \'review\') GROUP BY m.mark',
                ['u' => $userId, 's' => $subjectId]
            ) as $row) {
                $out[$row['mark']] = (int) $row['c'];
            }
        } catch (\PDOException) {
            // marks table not installed
        }
        return $out;
    }

    private function readFilters(Request $request): array
    {
        $mode = $request->string('mode');

        return [
            'sub_subject_id' => $request->int('sub'),
            'topic_id'       => $request->int('topic'),
            'difficulty'     => $request->string('difficulty'),
            'tag_id'         => $request->int('tag'),
            'q'              => mb_substr(trim($request->string('q')), 0, 100),
            'mode'           => in_array($mode, QbPracticeRepository::MODES, true) ? $mode : '',
        ];
    }

    private function grantedSubjectOr404(int $userId, string $uuid): array
    {
        $subject = (new QbSubjectRepository())->findByUuid($uuid);

        if ($subject === null
            || (int) $subject['depth'] !== 1
            || (int) $subject['is_active'] !== 1
            || !QbAccess::allowsSubject($userId, (int) $subject['id'])) {
            throw HttpException::notFound();
        }

        return $subject;
    }

    /** Every page of the bank carries its own stylesheet and script. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
