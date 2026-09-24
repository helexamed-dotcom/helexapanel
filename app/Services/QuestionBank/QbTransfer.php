<?php
declare(strict_types=1);

namespace HeleXa\Services\QuestionBank;

use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;

/**
 * JSON export and import for the question bank.
 *
 * The file format is deliberately forgiving on the way in, because it is
 * meant to be written by people and by AI models as often as by this
 * exporter:
 *
 *   {
 *     "format": "helexa-qbank", "version": 1,
 *     "questions": [
 *       {
 *         "id": "uuid — optional; an existing id updates that question",
 *         "subject": "درس", "sub_subject": "زیردرس", "topic": "عنوان",
 *         "difficulty": "easy|medium|hard|expert"  (or آسان/متوسط/دشوار/تخصصی),
 *         "status": "draft|published",
 *         "tags": ["برچسب", …],
 *         "stem": "متن سوال",           "stem_image": "data:image/png;base64,…",
 *         "options": [ {"text": "…", "correct": true, "image": null}, … ],
 *         "explanation": "پاسخ تشریحی", "explanation_image": null
 *       }
 *     ]
 *   }
 *
 * Also accepted: a bare array of questions; "options" as plain strings with
 * "answer" as the 1-based number of the correct one (or its text); "question"
 * for "stem"; a single string for "tags".
 *
 * Every question is validated and written on its own, so one bad row is
 * reported with its number and never blocks the rest of the file.
 */
final class QbTransfer
{
    public const FORMAT  = 'helexa-qbank';
    public const VERSION = 1;
    public const MAX_QUESTIONS = 5000;

    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 8;
    private const MAX_TEXT    = 8000;

    private const DIFFICULTY_ALIASES = [
        'آسان' => 'easy', 'ساده' => 'easy', 'متوسط' => 'medium', 'دشوار' => 'hard', 'سخت' => 'hard',
        'تخصصی' => 'expert', 'خیلی سخت' => 'expert', '1' => 'easy', '2' => 'medium', '3' => 'hard', '4' => 'expert',
    ];

    private QbQuestionRepository $questions;
    private QbSubjectRepository $subjects;
    private QbTagRepository $tags;

    /** @var array<string,int> "parentId|normalised title" → subject id */
    private array $subjectCache = [];
    /** @var array<string,int>|null normalised title → tag id */
    private ?array $tagCache = null;

    public function __construct()
    {
        $this->questions = new QbQuestionRepository();
        $this->subjects  = new QbSubjectRepository();
        $this->tags      = new QbTagRepository();
    }

    /* ============================================================ export */

    /**
     * Writes the export straight to output, a page of questions at a time,
     * so a bank of thousands never sits in memory as one array.
     */
    public function exportTo(array $filters, bool $withImages): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

        echo "{\n";
        echo '  "format": "' . self::FORMAT . '",' . "\n";
        echo '  "version": ' . self::VERSION . ",\n";
        echo '  "exported_at": ' . json_encode(date('c')) . ",\n";
        echo '  "count": ' . $this->questions->countMatching($filters) . ",\n";
        echo '  "questions": [';

        $first  = true;
        $offset = 0;
        do {
            $rows = $this->questions->search($filters, 300, $offset);
            $offset += 300;
            $ids     = array_map(static fn ($r) => (int) $r['id'], $rows);
            $tags    = $this->questions->tagsForMany($ids);
            $options = $this->questions->optionsForMany($ids);

            foreach ($rows as $row) {
                $item = $this->exportOne($row, $options[(int) $row['id']] ?? [], $tags[(int) $row['id']] ?? [], $withImages);
                $json = json_encode($item, $flags);
                echo ($first ? "\n" : ",\n") . '    ' . str_replace("\n", "\n    ", (string) $json);
                $first = false;
            }
            flush();
        } while (count($rows) === 300);

        echo ($first ? '' : "\n  ") . "],\n";

        // درسنامه‌ها of the tree, by title path — the same way questions are filed.
        echo '  "lesson_notes": ' . str_replace("\n", "\n  ", (string) json_encode($this->subjectNotes(), $flags)) . "\n}\n";
    }

    /** @return array<int,array{subject:string, sub_subject?:string, topic?:string, note:string}> */
    private function subjectNotes(): array
    {
        try {
            $rows = \HeleXa\Core\Database::select('SELECT id, parent_id, depth, title, lesson_note FROM qb_subjects');
        } catch (\PDOException) {
            return [];
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['lesson_note'] ?? '')) === '') {
                continue;
            }
            $path = [];
            for ($node = $row; $node !== null; $node = $node['parent_id'] !== null ? ($byId[(int) $node['parent_id']] ?? null) : null) {
                array_unshift($path, (string) $node['title']);
            }
            $entry = ['subject' => $path[0]];
            if (isset($path[1])) {
                $entry['sub_subject'] = $path[1];
            }
            if (isset($path[2])) {
                $entry['topic'] = $path[2];
            }
            $entry['note'] = (string) $row['lesson_note'];
            $out[] = $entry;
        }
        return $out;
    }

    private function exportOne(array $q, array $options, array $tags, bool $withImages): array
    {
        $item = [
            'id'          => $q['uuid'],
            'subject'     => $q['subject_title'],
            'sub_subject' => $q['sub_subject_title'],
            'topic'       => $q['topic_title'],
            'difficulty'  => $q['difficulty'],
            'status'      => $q['status'],
            'tags'        => array_values(array_map(static fn ($t) => (string) $t['title'], $tags)),
            'stem'        => $q['stem_text'],
            'options'     => [],
            'explanation' => $q['explanation_text'],
        ];
        if (trim((string) ($q['lesson_note'] ?? '')) !== '') {
            $item['lesson_note'] = $q['lesson_note'];
        }

        foreach ($options as $o) {
            $opt = ['text' => $o['body_text'], 'correct' => (bool) $o['is_correct']];
            if ($withImages && !empty($o['body_image'])) {
                $opt['image'] = $this->dataUrl($o['body_image']);
            }
            $item['options'][] = $opt;
        }

        if ($withImages) {
            $item['stem_image']        = $this->dataUrl($q['stem_image']);
            $item['explanation_image'] = $this->dataUrl($q['explanation_image']);
        }

        return $item;
    }

    private function dataUrl(?string $name): ?string
    {
        $file = QbImageStorage::resolve($name);
        if ($file === null) {
            return null;
        }
        $bytes = @file_get_contents($file['path']);

        return $bytes === false ? null : 'data:' . $file['mime'] . ';base64,' . base64_encode($bytes);
    }

    /* ============================================================ import */

    /**
     * @param array{create_missing:bool, status:string, dry_run:bool, can_publish:bool, author:?int} $opts
     *        status: keep | draft | published
     * @return array{total:int, created:int, updated:int, skipped:int, errors:array<int,string>, warnings:array<int,string>, subjects:int, tags:int}
     */
    public function import(string $json, array $opts): array
    {
        $report = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                   'errors' => [], 'warnings' => [], 'subjects' => 0, 'tags' => 0];

        // Tolerate a BOM and a ```json fence around text pasted from a chat.
        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($json)) ?? '';
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('فایل JSON معتبر نیست: ' . $e->getMessage());
        }

        $list = is_array($data) && array_is_list($data) ? $data : ($data['questions'] ?? null);
        if (!is_array($list) || $list === []) {
            throw new \RuntimeException('هیچ سوالی در فایل پیدا نشد (کلید "questions" لازم است).');
        }
        if (count($list) > self::MAX_QUESTIONS) {
            throw new \RuntimeException('در هر فایل حداکثر ' . fa((string) self::MAX_QUESTIONS) . ' سوال پذیرفته می‌شود.');
        }

        foreach (array_values($list) as $i => $raw) {
            $n = $i + 1;
            $report['total']++;
            $fresh = [];
            try {
                if (!is_array($raw)) {
                    throw new \RuntimeException('ساختار این سوال یک شیء نیست.');
                }
                $result = $this->importOne($raw, $opts, $report, $fresh);
                $report[$result]++;
            } catch (\Throwable $e) {
                foreach ($fresh as $name) {
                    QbImageStorage::forget($name);
                }
                $report['errors'][$n] = $e instanceof \RuntimeException ? $e->getMessage() : 'خطای داخلی هنگام ثبت.';
                if (!$e instanceof \RuntimeException) {
                    error_log('[qbank import] #' . $n . ': ' . $e->getMessage());
                }
            }
        }

        // درسنامه‌ها for the tree, filed by title path like the questions.
        $notes = is_array($data) && !array_is_list($data) ? ($data['lesson_notes'] ?? []) : [];
        if (is_array($notes)) {
            $report['lesson_notes'] = 0;
            foreach (array_slice(array_values($notes), 0, 2000) as $i => $note) {
                if (!is_array($note)) {
                    continue;
                }
                $text = $this->text($note['note'] ?? $note['text'] ?? $note['lesson_note'] ?? null);
                if ($text === null) {
                    continue;
                }
                [$s, $b, $tp] = $this->filing($note, $opts, $report);
                $target = $tp ?? $b ?? $s;
                if ($target === null) {
                    $report['warnings']['note-' . ($i + 1)] = 'درسنامه شماره ' . fa((string) ($i + 1)) . ' درس مشخصی نداشت.';
                    continue;
                }
                if (!$opts['dry_run']) {
                    $this->subjects->setLessonNote($target, $text);
                }
                $report['lesson_notes']++;
            }
        }

        return $report;
    }

    /** @return string created | updated | skipped */
    private function importOne(array $raw, array $opts, array &$report, array &$fresh): string
    {
        $n = $report['total'];

        // ----- text
        $stem = $this->text($raw['stem'] ?? $raw['question'] ?? $raw['text'] ?? null);
        $explanation = $this->text($raw['explanation'] ?? $raw['answer_explanation'] ?? $raw['description'] ?? null);

        // ----- difficulty
        $difficulty = trim((string) ($raw['difficulty'] ?? 'medium'));
        $difficulty = self::DIFFICULTY_ALIASES[$difficulty] ?? strtolower($difficulty);
        if (!in_array($difficulty, QbQuestionRepository::DIFFICULTIES, true)) {
            $report['warnings'][$n] = 'سطح سختی «' . $difficulty . '» ناشناخته بود؛ «متوسط» ثبت شد.';
            $difficulty = 'medium';
        }

        // ----- options
        $options = $this->options($raw, $opts['dry_run'], $fresh);
        if (count($options) < self::MIN_OPTIONS) {
            throw new \RuntimeException('دست‌کم ' . fa((string) self::MIN_OPTIONS) . ' گزینه لازم است.');
        }
        $correct = count(array_filter($options, static fn ($o) => $o['is_correct']));

        // ----- images
        $stemImage        = $this->image($raw['stem_image'] ?? null, $opts['dry_run'], $fresh);
        $explanationImage = $this->image($raw['explanation_image'] ?? null, $opts['dry_run'], $fresh);
        if ($stem === null && $stemImage === null) {
            throw new \RuntimeException('متن یا تصویر صورت سوال خالی است.');
        }

        // ----- status
        $status = match ($opts['status']) {
            'draft'     => 'draft',
            'published' => 'published',
            default     => ($raw['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
        };
        if ($status === 'published' && !$opts['can_publish']) {
            $status = 'draft';
        }
        if ($status === 'published' && $correct !== 1) {
            $status = 'draft';
            $report['warnings'][$n] = 'دقیقاً یک گزینه صحیح لازم است؛ سوال به‌صورت پیش‌نویس ثبت شد.';
        }

        // ----- filing and tags
        [$subjectId, $subId, $topicId] = $this->filing($raw, $opts, $report);
        $tagIds = $this->tagIds($raw['tags'] ?? [], $opts, $report);

        $data = [
            'subject_id'        => $subjectId,
            'sub_subject_id'    => $subId,
            'topic_id'          => $topicId,
            'stem_text'         => $stem,
            'stem_image'        => $stemImage,
            'difficulty'        => $difficulty,
            'explanation_text'  => $explanation,
            'explanation_image' => $explanationImage,
            'status'            => $status,
        ];

        $uuid     = is_string($raw['id'] ?? null) ? trim($raw['id']) : '';
        $existing = $uuid !== '' ? $this->questions->findByUuid($uuid) : null;

        if ($opts['dry_run']) {
            return $existing !== null ? 'updated' : 'created';
        }

        if ($existing !== null) {
            $oldImages = $this->questions->imageNames((int) $existing['id']);
            // Images the file does not carry are kept from the stored question.
            if ($stemImage === null && !array_key_exists('stem_image', $raw)) {
                $data['stem_image'] = $existing['stem_image'];
            }
            if ($explanationImage === null && !array_key_exists('explanation_image', $raw)) {
                $data['explanation_image'] = $existing['explanation_image'];
            }
            $this->questions->update((int) $existing['id'], (int) $existing['version'], $data, $options, $tagIds);
            if (array_key_exists('lesson_note', $raw) || array_key_exists('lesson', $raw)) {
                $this->questions->setLessonNote((int) $existing['id'], $this->text($raw['lesson_note'] ?? $raw['lesson'] ?? null));
            }

            $kept = $this->questions->imageNames((int) $existing['id']);
            foreach (array_diff($oldImages, $kept) as $gone) {
                QbImageStorage::forget($gone);
            }
            return 'updated';
        }

        $newUuid = $this->questions->create($data, $options, $tagIds, $opts['author']);
        $note    = $this->text($raw['lesson_note'] ?? $raw['lesson'] ?? null);
        if ($note !== null) {
            $created = $this->questions->findByUuid($newUuid);
            if ($created !== null) {
                $this->questions->setLessonNote((int) $created['id'], $note);
            }
        }
        return 'created';
    }

    /** @return array<int,array{body_text:?string, body_image:?string, is_correct:bool}> */
    private function options(array $raw, bool $dryRun, array &$fresh): array
    {
        $list = $raw['options'] ?? $raw['choices'] ?? [];
        if (!is_array($list)) {
            throw new \RuntimeException('«options» باید یک آرایه باشد.');
        }
        $list = array_slice(array_values($list), 0, self::MAX_OPTIONS);

        // "answer": 2 (1-based), "B", or the correct option's text.
        $answer = $raw['answer'] ?? $raw['correct'] ?? null;
        $answerIndex = null;
        if (is_int($answer) || (is_string($answer) && ctype_digit($answer))) {
            $answerIndex = (int) $answer - 1;
        } elseif (is_string($answer) && preg_match('/^[A-Ha-h]$/', trim($answer)) === 1) {
            $answerIndex = ord(strtoupper(trim($answer))) - 65;
        } elseif (is_string($answer) && isset(['الف' => 1, 'ب' => 1, 'ج' => 1, 'د' => 1][trim($answer)])) {
            $answerIndex = ['الف' => 0, 'ب' => 1, 'ج' => 2, 'د' => 3][trim($answer)];
        }

        $out = [];
        foreach ($list as $i => $o) {
            if (is_string($o) || is_numeric($o)) {
                $o = ['text' => (string) $o];
            }
            if (!is_array($o)) {
                continue;
            }
            $text  = $this->text($o['text'] ?? $o['body'] ?? $o['option'] ?? null);
            $image = $this->image($o['image'] ?? null, $dryRun, $fresh);
            if ($text === null && $image === null) {
                continue;
            }
            $isCorrect = filter_var($o['correct'] ?? $o['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($answerIndex !== null) {
                $isCorrect = $i === $answerIndex;
            } elseif (is_string($answer) && $text !== null && $answerIndex === null && trim($answer) === $text) {
                $isCorrect = true;
            }
            $out[] = ['body_text' => $text, 'body_image' => $image, 'is_correct' => $isCorrect];
        }

        return $out;
    }

    /** @return array{0:?int,1:?int,2:?int} */
    private function filing(array $raw, array $opts, array &$report): array
    {
        $titles = [
            $this->text($raw['subject'] ?? null),
            $this->text($raw['sub_subject'] ?? $raw['subsubject'] ?? null),
            $this->text($raw['topic'] ?? null),
        ];
        $ids    = [null, null, null];
        $parent = null;

        foreach ($titles as $depth => $title) {
            if ($title === null) {
                break; // a lower level without its parent cannot be placed
            }
            $id = $this->subjectId($parent, mb_substr($title, 0, 191), $opts, $report);
            if ($id === null) {
                break;
            }
            $ids[$depth] = $parent = $id;
        }

        return $ids;
    }

    private function subjectId(?int $parentId, string $title, array $opts, array &$report): ?int
    {
        $key = ($parentId ?? 0) . '|' . self::norm($title);
        if (isset($this->subjectCache[$key])) {
            return $this->subjectCache[$key];
        }
        foreach ($this->subjects->children($parentId) as $row) {
            $this->subjectCache[($parentId ?? 0) . '|' . self::norm((string) $row['title'])] = (int) $row['id'];
        }
        if (isset($this->subjectCache[$key])) {
            return $this->subjectCache[$key];
        }
        if (!$opts['create_missing']) {
            throw new \RuntimeException('«' . $title . '» در دروس بانک سوال وجود ندارد.');
        }

        $report['subjects']++;
        if ($opts['dry_run']) {
            // Pretend-ids keep the chain going without writing anything.
            return $this->subjectCache[$key] = -count($this->subjectCache) - 1;
        }

        return $this->subjectCache[$key] = $this->subjects->create($parentId, ['title' => $title], $opts['author']);
    }

    /** @return array<int,int> */
    private function tagIds(mixed $tags, array $opts, array &$report): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,،]/u', $tags) ?: [];
        }
        if (!is_array($tags)) {
            return [];
        }

        if ($this->tagCache === null) {
            $this->tagCache = [];
            foreach ($this->tags->all() as $t) {
                $this->tagCache[self::norm((string) $t['title'])] = (int) $t['id'];
            }
        }

        $ids = [];
        foreach (array_slice($tags, 0, 20) as $title) {
            $title = is_scalar($title) ? mb_substr(trim((string) $title), 0, 96) : '';
            if ($title === '') {
                continue;
            }
            $key = self::norm($title);
            if (!isset($this->tagCache[$key])) {
                if (!$opts['create_missing']) {
                    continue;
                }
                $report['tags']++;
                $this->tagCache[$key] = $opts['dry_run'] ? 0 : $this->tags->create($title, 'chip-blue', 0);
            }
            $ids[] = $this->tagCache[$key];
        }

        return array_values(array_filter(array_unique($ids)));
    }

    private function image(mixed $value, bool $dryRun, array &$fresh): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $bytes = QbImageStorage::decodeDataUrl($value);
        if ($bytes === null) {
            throw new \RuntimeException('تصویر باید به شکل data:image/…;base64 باشد.');
        }
        if ($dryRun) {
            return 'dry-run';
        }
        $name    = QbImageStorage::storeBlob($bytes);
        $fresh[] = $name;

        return $name;
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", (string) $value));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT);
    }

    /** Same title however the Arabic/Persian letters or spacing were typed. */
    private static function norm(string $s): string
    {
        $s = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $s);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? $s);
    }

    /* ======================================================= AI helper */

    /** A ready-to-paste prompt that makes a chat model produce an importable file. */
    public static function aiPrompt(): string
    {
        return <<<TXT
برای بانک سوال یک فایل JSON دقیقاً با ساختار زیر بساز. فقط JSON خالص برگردان، بدون توضیح اضافه.

{
  "format": "helexa-qbank",
  "version": 1,
  "questions": [
    {
      "subject": "نام درس (مثلاً فیزیولوژی)",
      "sub_subject": "نام زیردرس (اختیاری)",
      "topic": "نام عنوان (اختیاری)",
      "difficulty": "easy | medium | hard | expert",
      "status": "published",
      "tags": ["برچسب ۱", "برچسب ۲"],
      "stem": "متن کامل صورت سوال",
      "options": [
        {"text": "گزینه اول", "correct": false},
        {"text": "گزینه دوم", "correct": true},
        {"text": "گزینه سوم", "correct": false},
        {"text": "گزینه چهارم", "correct": false}
      ],
      "explanation": "پاسخ تشریحی کامل: چرا گزینه درست، درست است و بقیه نادرست‌اند",
      "lesson_note": "(اختیاری) درسنامه مخصوص همین سوال"
    }
  ],
  "lesson_notes": [
    {
      "subject": "نام درس",
      "sub_subject": "نام زیردرس (اختیاری)",
      "topic": "نام عنوان (اختیاری)",
      "note": "## عنوان درسنامه\\nیک خلاصه آموزشی کوتاه و دقیق از همین بخش.\\n- نکته کلیدی اول\\n- نکته کلیدی دوم\\n**نکته امتحانی:** …"
    }
  ]
}

قواعد:
- هر سوال دقیقاً یک گزینه با "correct": true داشته باشد (۲ تا ۸ گزینه).
- جای گزینه صحیح را در سوال‌ها تصادفی بچین.
- difficulty فقط یکی از easy, medium, hard, expert باشد.
- "id" ننویس (برای سوال جدید لازم نیست).
- برای هر «عنوان» (یا زیردرس) که سوال دارد، در "lesson_notes" یک درسنامه بنویس: خلاصه آموزشی
  ۱۵۰ تا ۴۰۰ کلمه، با تیتر (##)، فهرست نکات (-) و نکات مهم امتحانی پررنگ (**…**). دانشجو بعد از
  پاسخ دادن به هر سوال، درسنامه همان بخش را می‌بیند.
- موضوع و تعداد سوال: [اینجا بنویس، مثلاً ۲۰ سوال از فیزیولوژی قلب، سطح متوسط]
TXT;
    }

    public static function sample(): array
    {
        return [
            'format'    => self::FORMAT,
            'version'   => self::VERSION,
            'questions' => [
                [
                    'subject'     => 'فیزیولوژی',
                    'sub_subject' => 'قلب',
                    'topic'       => 'چرخه قلبی',
                    'difficulty'  => 'medium',
                    'status'      => 'published',
                    'tags'        => ['علوم پایه', 'تالیفی'],
                    'stem'        => 'صدای اول قلب (S1) در اثر بسته شدن کدام دریچه‌ها ایجاد می‌شود؟',
                    'options'     => [
                        ['text' => 'آئورت و پولمونر', 'correct' => false],
                        ['text' => 'میترال و تریکوسپید', 'correct' => true],
                        ['text' => 'فقط میترال', 'correct' => false],
                        ['text' => 'آئورت و میترال', 'correct' => false],
                    ],
                    'explanation' => 'S1 با بسته شدن دریچه‌های دهلیزی‌بطنی (میترال و تریکوسپید) در ابتدای سیستول بطنی شنیده می‌شود؛ S2 مربوط به دریچه‌های سینی است.',
                ],
                [
                    'subject'     => 'فیزیولوژی',
                    'difficulty'  => 'easy',
                    'tags'        => 'علوم پایه',
                    'stem'        => 'واحد عملکردی کلیه چیست؟',
                    'options'     => ['نفرون', 'آلوئول', 'سارکومر', 'لوبول'],
                    'answer'      => 1,
                    'explanation' => 'نفرون واحد ساختاری و عملکردی کلیه است.',
                ],
            ],
            'lesson_notes' => [
                [
                    'subject'     => 'فیزیولوژی',
                    'sub_subject' => 'قلب',
                    'topic'       => 'چرخه قلبی',
                    'note'        => "## صداهای قلب\nصدای اول (S1) با بسته شدن دریچه‌های میترال و تریکوسپید در شروع سیستول ایجاد می‌شود.\n- S2: بسته شدن دریچه‌های آئورت و پولمونر\n- S3: پرشدگی سریع بطن (در نارسایی قلب)\n**نکته امتحانی:** S4 همیشه پاتولوژیک است.",
                ],
            ],
        ];
    }
}
