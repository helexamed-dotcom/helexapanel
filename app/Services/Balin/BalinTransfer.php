<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinBlockRepository;
use HeleXa\Models\Balin\BalinCharacterRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinMediaRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStageRepository;

/**
 * JSON import / export for Balin island: a whole lesson — its stages, every
 * block in order, the characters speaking, its question bank and its
 * checkpoint exams — in one file.
 *
 * The format is written for people and AI models as much as for this
 * exporter, so the importer is forgiving: names instead of ids, a question
 * written inline inside the block that asks it, options as plain strings with
 * an "answer" number, Persian difficulty words. Everything it has to guess or
 * skip is reported.
 *
 * Every lesson is imported in its own transaction: a lesson either arrives
 * whole or not at all. A dry run imports everything inside one transaction
 * and rolls it back, so it checks exactly what a real run would do.
 */
final class BalinTransfer
{
    public const FORMAT  = 'helexa-balin';
    public const VERSION = 1;

    public const CORE_TYPES     = ['chat', 'text', 'finding', 'hint', 'warning', 'system', 'question',
                                   'image', 'audio', 'video', 'divider', 'checkpoint_anchor'];
    public const CLINICAL_TYPES = ['vitals', 'lab', 'pearl', 'ddx', 'reference'];
    public const TEXT_TYPES     = ['chat', 'text', 'finding', 'hint', 'warning', 'system',
                                   'vitals', 'lab', 'pearl', 'ddx', 'reference'];

    private const TYPE_ALIASES = [
        'message' => 'chat', 'dialog' => 'chat', 'dialogue' => 'chat', 'note' => 'text', 'paragraph' => 'text',
        'tip' => 'hint', 'alert' => 'warning', 'labs' => 'lab', 'lab_results' => 'lab', 'vital' => 'vitals',
        'vital_signs' => 'vitals', 'differential' => 'ddx', 'differentials' => 'ddx', 'key_point' => 'pearl',
        'pearls' => 'pearl', 'references' => 'reference', 'source' => 'reference', 'exam' => 'checkpoint_anchor',
        'checkpoint' => 'checkpoint_anchor', 'mcq' => 'question',
    ];

    private const DIFFICULTY = [
        'easy' => 'easy', 'medium' => 'medium', 'hard' => 'hard', 'expert' => 'expert',
        'آسان' => 'easy', 'ساده' => 'easy', 'متوسط' => 'medium', 'دشوار' => 'hard', 'سخت' => 'hard', 'تخصصی' => 'expert',
    ];

    private const CHAR_TYPES = ['teacher', 'student', 'doctor', 'patient', 'nurse', 'other'];

    private BalinLessonRepository $lessons;
    private BalinStageRepository $stages;
    private BalinBlockRepository $blocks;
    private BalinQuestionRepository $questions;
    private BalinCheckpointRepository $exams;
    private BalinCharacterRepository $characters;
    private BalinSkillTrackRepository $tracks;
    private BalinMediaRepository $media;

    /** @var array<string,int> normalised name => id */
    private array $charByName = [];
    /** @var array<string,int> slug or normalised name => id */
    private array $trackByKey = [];
    private ?bool $clinicalReady = null;
    /** @var array<int,string> media files written during the current lesson */
    private array $freshMedia = [];
    private array $report = [];

    public function __construct()
    {
        $this->lessons    = new BalinLessonRepository();
        $this->stages     = new BalinStageRepository();
        $this->blocks     = new BalinBlockRepository();
        $this->questions  = new BalinQuestionRepository();
        $this->exams      = new BalinCheckpointRepository();
        $this->characters = new BalinCharacterRepository();
        $this->tracks     = new BalinSkillTrackRepository();
        $this->media      = new BalinMediaRepository();
    }

    /** Whether the clinical block types migration has been run. */
    public function clinicalReady(): bool
    {
        if ($this->clinicalReady === null) {
            try {
                $row = Database::selectOne("SHOW COLUMNS FROM balin_blocks LIKE 'block_type'");
                $this->clinicalReady = $row !== null && str_contains((string) ($row['Type'] ?? $row['type'] ?? ''), "'vitals'");
            } catch (\PDOException) {
                $this->clinicalReady = false;
            }
        }
        return $this->clinicalReady;
    }

    /* ================================================================ export */

    /** @param array<int,int> $lessonIds */
    public function export(array $lessonIds): array
    {
        $usedChars  = [];
        $usedTracks = [];
        $out        = [];

        foreach ($lessonIds as $lessonId) {
            $lesson = $this->lessons->findById((int) $lessonId);
            if ($lesson === null) {
                continue;
            }
            $out[] = $this->exportLesson($lesson, $usedChars, $usedTracks);
        }

        $characters = [];
        foreach ($this->characters->all() as $c) {
            if (isset($usedChars[(int) $c['id']])) {
                $characters[] = [
                    'name'   => $c['name'],
                    'type'   => $c['char_type'],
                    'gender' => $c['gender'],
                    'icon'   => $c['icon'],
                    'side'   => $c['side'],
                    'color'  => $c['color'],
                ];
            }
        }

        $tracks = [];
        foreach ($this->tracks->all() as $t) {
            if (isset($usedTracks[(int) $t['id']])) {
                $tracks[] = [
                    'slug'        => $t['slug'],
                    'name'        => $t['name'],
                    'name_en'     => $t['name_en'],
                    'icon'        => $t['icon'],
                    'color'       => $t['color'],
                    'category'    => $t['category'],
                    'description' => $t['description'],
                ];
            }
        }

        return [
            'format'       => self::FORMAT,
            'version'      => self::VERSION,
            'exported_at'  => date('c'),
            'characters'   => $characters,
            'skill_tracks' => $tracks,
            'lessons'      => $out,
        ];
    }

    private function exportLesson(array $lesson, array &$usedChars, array &$usedTracks): array
    {
        $lessonId = (int) $lesson['id'];
        $stages   = $this->stages->forLesson($lessonId);
        $stageKey = [];
        foreach ($stages as $i => $s) {
            $stageKey[(int) $s['id']] = 's' . ($i + 1);
        }

        $trackSlugs = [];
        foreach ($this->tracks->all() as $t) {
            $trackSlugs[(int) $t['id']] = (string) $t['slug'];
        }

        // Every question of the lesson, keyed; blocks point at them.
        $bank = [];
        $qKey = [];
        foreach (array_reverse($this->questions->forLesson($lessonId)) as $i => $q) {
            $key = 'q' . ($i + 1);
            $qKey[(int) $q['id']] = $key;
            $tags = [];
            foreach ($this->questions->skillTrackIds((int) $q['id']) as $tid) {
                $usedTracks[$tid] = true;
                if (isset($trackSlugs[$tid])) {
                    $tags[] = $trackSlugs[$tid];
                }
            }
            $bank[] = [
                'key'             => $key,
                'stage'           => $q['stage_id'] !== null ? ($stageKey[(int) $q['stage_id']] ?? null) : null,
                'prompt'          => $q['prompt'],
                'options'         => array_map(static fn (array $o): array => [
                    'text'    => $o['body'],
                    'correct' => (int) $o['is_correct'] === 1,
                ], $this->questions->options((int) $q['id'])),
                'explanation'     => $q['explanation'],
                'hint'            => $q['hint'],
                'difficulty'      => $q['difficulty'],
                'xp_reward'       => (int) $q['xp_reward'],
                'required'        => (int) $q['is_required'] === 1,
                'final_case_step' => (int) $q['is_final_case_step'] === 1,
                'status'          => $q['status'],
                'skill_tracks'    => $tags,
            ];
        }

        $examKey = [];
        $exams   = [];
        foreach ($this->exams->forLesson($lessonId) as $i => $e) {
            $key = 'e' . ($i + 1);
            $examKey[(int) $e['id']] = $key;
            if ($e['primary_skill_track_id'] !== null) {
                $usedTracks[(int) $e['primary_skill_track_id']] = true;
            }
            $secondary = json_decode((string) ($e['secondary_skill_track_ids'] ?? '[]'), true) ?: [];
            foreach ($secondary as $tid) {
                $usedTracks[(int) $tid] = true;
            }
            $exams[] = [
                'key'                => $key,
                'title'              => $e['title'],
                'description'        => $e['description'],
                'position'           => $e['position_type'],
                'anchor_stage'       => $e['anchor_stage_id'] !== null ? ($stageKey[(int) $e['anchor_stage_id']] ?? null) : null,
                'mode'               => $e['question_source_mode'],
                'num_questions'      => (int) $e['num_questions'],
                'pass_percent'       => (int) $e['pass_threshold_percent'],
                'gating'             => (int) $e['is_gating'] === 1,
                'max_attempts'       => $e['max_attempts'] !== null ? (int) $e['max_attempts'] : null,
                'cooldown_hours'     => (int) $e['cooldown_hours_between_attempts'],
                'time_limit_minutes' => $e['time_limit_minutes'] !== null ? (int) $e['time_limit_minutes'] : null,
                'xp_reward'          => (int) $e['xp_reward'],
                'primary_skill_track'    => $e['primary_skill_track_id'] !== null ? ($trackSlugs[(int) $e['primary_skill_track_id']] ?? null) : null,
                'secondary_skill_tracks' => array_values(array_filter(array_map(static fn ($tid) => $trackSlugs[(int) $tid] ?? null, $secondary))),
                'status'             => $e['status'],
                'questions'          => array_values(array_filter(array_map(
                    static fn (array $q) => $qKey[(int) $q['id']] ?? null,
                    $this->exams->fixedQuestions((int) $e['id'])
                ))),
            ];
        }

        $stageOut = [];
        foreach ($stages as $s) {
            $blocks = [];
            foreach ($this->blocks->forStage((int) $s['id']) as $b) {
                $item = ['type' => $b['block_type']];
                if ($b['character_id'] !== null) {
                    $usedChars[(int) $b['character_id']] = true;
                    $item['character'] = $b['character_name'];
                }
                if ($b['side_override'] !== null) {
                    $item['side'] = $b['side_override'];
                }
                if ($b['body'] !== null && $b['body'] !== '') {
                    $item['text'] = $b['body'];
                }
                if ($b['question_id'] !== null) {
                    $item['question_ref'] = $qKey[(int) $b['question_id']] ?? null;
                }
                if ($b['media_uuid'] !== null) {
                    $item['media'] = $b['media_uuid'];
                }
                if ($b['checkpoint_exam_id'] !== null) {
                    $item['exam_ref'] = $examKey[(int) $b['checkpoint_exam_id']] ?? null;
                }
                if ((int) $b['is_required'] !== 1) {
                    $item['required'] = false;
                }
                if ($b['status'] !== 'published') {
                    $item['status'] = $b['status'];
                }
                $blocks[] = $item;
            }

            $stageOut[] = [
                'key'               => $stageKey[(int) $s['id']],
                'title'             => $s['title'],
                'subtitle'          => $s['subtitle'],
                'description'       => $s['description'],
                'is_final_case'     => (int) $s['is_final_case'] === 1,
                'xp_reward'         => (int) $s['xp_reward'],
                'estimated_minutes' => (int) $s['estimated_minutes'],
                'status'            => $s['status'],
                'blocks'            => $blocks,
            ];
        }

        return [
            'title'             => $lesson['title'],
            'slug'              => $lesson['slug'],
            'description'       => $lesson['description'],
            'icon'              => $lesson['icon'],
            'color'             => $lesson['color'],
            'estimated_minutes' => (int) $lesson['estimated_minutes'],
            'xp_reward'         => (int) $lesson['xp_reward'],
            'extra_notes'       => $lesson['extra_notes'],
            'status'            => $lesson['status'],
            'stages'            => $stageOut,
            'question_bank'     => $bank,
            'checkpoint_exams'  => $exams,
        ];
    }

    /* ================================================================ import */

    /**
     * @param array{dry_run:bool, status:string, can_publish:bool, author:?int, create_missing:bool} $opts
     */
    public function import(string $json, array $opts): array
    {
        $this->report = [
            'lessons' => [], 'errors' => [], 'warnings' => [],
            'counts'  => ['lessons' => 0, 'stages' => 0, 'blocks' => 0, 'questions' => 0, 'exams' => 0, 'characters' => 0, 'tracks' => 0],
            'dry_run' => $opts['dry_run'],
        ];

        $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($json)) ?? '';
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }
        try {
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('فایل JSON معتبر نیست: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new \RuntimeException('ساختار فایل معتبر نیست.');
        }

        // A bare lesson, a list of lessons, or the full document.
        if (isset($data['stages'])) {
            $data = ['lessons' => [$data]];
        } elseif (array_is_list($data)) {
            $data = ['lessons' => $data];
        }
        $lessons = $data['lessons'] ?? (isset($data['lesson']) ? [$data['lesson']] : null);
        if (!is_array($lessons) || $lessons === []) {
            throw new \RuntimeException('هیچ درسی در فایل پیدا نشد (کلید "lessons" لازم است).');
        }
        if (count($lessons) > 50) {
            throw new \RuntimeException('در هر فایل حداکثر ۵۰ درس پذیرفته می‌شود.');
        }

        $pdo = Database::connection();
        if ($opts['dry_run']) {
            $pdo->beginTransaction();
        }

        try {
            $this->loadCatalog();
            $this->importCharacters(is_array($data['characters'] ?? null) ? $data['characters'] : []);
            $this->importTracks(is_array($data['skill_tracks'] ?? null) ? $data['skill_tracks'] : []);

            foreach (array_values($lessons) as $i => $lesson) {
                $label = 'درس ' . fa((string) ($i + 1));
                if (!is_array($lesson)) {
                    $this->error($label . ': ساختار درس معتبر نیست.');
                    continue;
                }
                $label .= ' «' . mb_substr((string) ($lesson['title'] ?? ''), 0, 60) . '»';
                $this->freshMedia = [];
                $countsBefore     = $this->report['counts'];
                try {
                    $result = Database::transaction(fn () => $this->importLesson($lesson, $opts, $label));
                    $this->report['lessons'][] = $result;
                    $this->report['counts']['lessons']++;
                } catch (\Throwable $e) {
                    foreach ($this->freshMedia as $path) {
                        BalinMediaStorage::delete($path);
                    }
                    // Whatever this lesson created was rolled back, including
                    // characters and tracks it added; forget them too.
                    $this->report['counts'] = $countsBefore;
                    if (!$opts['dry_run']) {
                        $this->charByName = [];
                        $this->trackByKey = [];
                        $this->loadCatalog();
                    }
                    $this->error($label . ': ' . ($e instanceof \RuntimeException ? $e->getMessage() : 'خطای داخلی هنگام ثبت.'));
                    if (!$e instanceof \RuntimeException) {
                        error_log('[balin import] ' . $e->getMessage());
                    }
                }
            }
        } finally {
            if ($opts['dry_run'] && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return $this->report;
    }

    private function importLesson(array $l, array $opts, string $label): array
    {
        $title = $this->text($l['title'] ?? null, 191);
        if ($title === null) {
            throw new \RuntimeException('عنوان درس خالی است.');
        }
        $stages = $l['stages'] ?? [];
        if (!is_array($stages) || $stages === []) {
            throw new \RuntimeException('درس هیچ مرحله‌ای ندارد (کلید "stages").');
        }
        if (count($stages) > 60) {
            throw new \RuntimeException('حداکثر ۶۰ مرحله در هر درس.');
        }

        $status = $this->status($l['status'] ?? null, $opts, 'draft');
        $slug   = $this->uniqueSlug((string) ($l['slug'] ?? '') !== '' ? (string) $l['slug'] : $title);

        $lessonId = $this->lessons->create([
            'uuid'              => Str::uuid4(),
            'slug'              => $slug,
            'title'             => $title,
            'description'       => $this->text($l['description'] ?? null, 5000),
            'icon'              => $this->text($l['icon'] ?? null, 32),
            'color'             => $this->color($l['color'] ?? null),
            'estimated_minutes' => $this->int($l['estimated_minutes'] ?? 0, 0, 1000),
            'xp_reward'         => $this->int($l['xp_reward'] ?? 0, 0, 5000),
            'extra_notes'       => $this->text($l['extra_notes'] ?? null, 5000),
            'status'            => 'draft',
            'created_by'        => $opts['author'],
        ]);
        if ($status === 'published') {
            $this->lessons->setStatus($lessonId, 'published');
        }
        // An imported lesson reaches nobody until it is given out, exactly
        // like one made in the builder — except for full-access packages.
        \HeleXa\Services\PackageAccess::contentAdded('balin_lesson', $lessonId, $opts['author'] ?? null);

        // Stages first: questions and exams point at them.
        $stageIds = [];      // key => id
        $stageList = [];     // [id, data]
        foreach (array_values($stages) as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $stageTitle = $this->text($s['title'] ?? null, 191) ?? ('مرحله ' . fa((string) ($i + 1)));
            $id = $this->stages->create([
                'uuid'              => Str::uuid4(),
                'lesson_id'         => $lessonId,
                'title'             => $stageTitle,
                'subtitle'          => $this->text($s['subtitle'] ?? null, 191),
                'description'       => $this->text($s['description'] ?? null, 5000),
                'display_order'     => ($i + 1) * 1000,
                'status'            => $this->status($s['status'] ?? null, $opts, $status),
                'is_final_case'     => $this->bool($s['is_final_case'] ?? false),
                'xp_reward'         => $this->int($s['xp_reward'] ?? 0, 0, 5000),
                'estimated_minutes' => $this->int($s['estimated_minutes'] ?? 0, 0, 600),
            ]);
            $this->report['counts']['stages']++;
            $stageIds['#' . ($i + 1)] = $id;
            if (isset($s['key']) && is_scalar($s['key'])) {
                $stageIds[(string) $s['key']] = $id;
            }
            $stageIds['t:' . self::norm($stageTitle)] = $id;
            $stageList[] = [$id, $s, $i + 1];
        }

        // The question bank.
        $qIds = [];
        foreach (is_array($l['question_bank'] ?? null) ? array_values($l['question_bank']) : [] as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $where = $label . '، بانک سوال ' . fa((string) ($i + 1));
            $stageId = $this->stageRef($q['stage'] ?? null, $stageIds);
            $id = $this->createQuestion($q, $lessonId, $stageId, $opts, $where);
            if ($id !== null && isset($q['key']) && is_scalar($q['key'])) {
                $qIds[(string) $q['key']] = $id;
            }
        }

        // Exams, before blocks that anchor them. They follow the lesson's
        // status unless they say otherwise.
        $opts['default_status'] = $status;
        $examIds = [];
        foreach (is_array($l['checkpoint_exams'] ?? null) ? array_values($l['checkpoint_exams']) : [] as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $where = $label . '، آزمون ' . fa((string) ($i + 1));
            $id = $this->createExam($e, $lessonId, $stageIds, $qIds, $opts, $where);
            if ($id !== null && isset($e['key']) && is_scalar($e['key'])) {
                $examIds[(string) $e['key']] = $id;
            }
        }

        // Blocks.
        foreach ($stageList as [$stageId, $s, $n]) {
            $order = 0;
            $blocks = is_array($s['blocks'] ?? null) ? array_values($s['blocks']) : [];
            if (count($blocks) > 300) {
                $this->warn($label . '، مرحله ' . fa((string) $n) . ': فقط ۳۰۰ بلوک اول وارد شد.');
                $blocks = array_slice($blocks, 0, 300);
            }
            foreach ($blocks as $j => $b) {
                $where = $label . '، مرحله ' . fa((string) $n) . '، بلوک ' . fa((string) ($j + 1));
                if (!is_array($b)) {
                    $this->warn($where . ': نادیده گرفته شد (ساختار معتبر نیست).');
                    continue;
                }
                $order += 1000;
                if ($this->createBlock($b, $stageId, $lessonId, $order, $qIds, $examIds, $opts, $where)) {
                    $this->report['counts']['blocks']++;
                }
            }
        }

        $lesson = $this->lessons->findById($lessonId);

        return ['title' => $title, 'uuid' => $lesson['uuid'] ?? '', 'stages' => count($stageList)];
    }

    private function createBlock(array $b, int $stageId, int $lessonId, int $order, array &$qIds, array $examIds, array $opts, string $where): bool
    {
        $type = strtolower(trim((string) ($b['type'] ?? $b['block_type'] ?? '')));
        $type = self::TYPE_ALIASES[$type] ?? $type;
        if ($type === '' && isset($b['question'])) {
            $type = 'question';
        }
        if (!in_array($type, array_merge(self::CORE_TYPES, self::CLINICAL_TYPES), true)) {
            $this->warn($where . ': نوع بلوک «' . $type . '» ناشناخته است و وارد نشد.');
            return false;
        }

        $text = $this->blockText($type, $b);

        // Without the migration, clinical blocks become the closest classic type.
        if (in_array($type, self::CLINICAL_TYPES, true) && !$this->clinicalReady()) {
            $map  = ['vitals' => 'finding', 'lab' => 'finding', 'pearl' => 'hint', 'ddx' => 'text', 'reference' => 'text'];
            $head = ['vitals' => 'علائم حیاتی', 'lab' => 'نتایج آزمایش', 'pearl' => 'نکته کلیدی', 'ddx' => 'تشخیص‌های افتراقی', 'reference' => 'منبع'][$type];
            $text = $text !== null ? $head . ":\n" . $text : null;
            $type = $map[$type];
        }

        $data = [
            'uuid'          => Str::uuid4(),
            'stage_id'      => $stageId,
            'block_type'    => $type,
            'display_order' => $order,
            'is_required'   => array_key_exists('required', $b) ? $this->bool($b['required']) : true,
            'status'        => in_array($b['status'] ?? '', ['draft', 'published'], true) ? $b['status'] : 'published',
            'side_override' => in_array($b['side'] ?? '', ['left', 'right'], true) ? $b['side'] : null,
            'body'          => in_array($type, self::TEXT_TYPES, true) ? $text : null,
            'animation'     => $this->text($b['animation'] ?? null, 32),
        ];

        if (in_array($type, self::TEXT_TYPES, true) && $text === null) {
            $this->warn($where . ': متن بلوک خالی است و وارد نشد.');
            return false;
        }

        switch ($type) {
            case 'chat':
                $name = $this->text($b['character'] ?? $b['speaker'] ?? null, 120);
                if ($name === null) {
                    $this->warn($where . ': پیام گفت‌وگو شخصیت ندارد و وارد نشد.');
                    return false;
                }
                $charId = $this->characterId($name, $opts, $b);
                if ($charId === null) {
                    $this->warn($where . ': شخصیت «' . $name . '» وجود ندارد.');
                    return false;
                }
                $data['character_id'] = $charId;
                break;

            case 'question':
                $qid = null;
                if (isset($b['question']) && is_array($b['question'])) {
                    $qid = $this->createQuestion($b['question'], $lessonId, $stageId, $opts, $where);
                    if ($qid !== null && isset($b['question']['key']) && is_scalar($b['question']['key'])) {
                        $qIds[(string) $b['question']['key']] = $qid;
                    }
                } else {
                    $ref = $b['question_ref'] ?? $b['question_key'] ?? null;
                    $qid = is_scalar($ref) ? ($qIds[(string) $ref] ?? null) : null;
                    if ($qid === null) {
                        $this->warn($where . ': سؤال «' . (is_scalar($ref) ? $ref : '') . '» در بانک سوال پیدا نشد.');
                    } else {
                        // A bank question gets the stage of the first block that asks it.
                        Database::execute(
                            'UPDATE balin_questions SET stage_id = :stage WHERE id = :id AND stage_id IS NULL',
                            ['stage' => $stageId, 'id' => $qid]
                        );
                    }
                }
                if ($qid === null) {
                    return false;
                }
                $data['question_id'] = $qid;
                break;

            case 'image':
            case 'audio':
            case 'video':
                $mediaId = $this->mediaId($type, $b, $opts, $where);
                if ($mediaId === null) {
                    return false;
                }
                $data['media_id'] = $mediaId;
                break;

            case 'checkpoint_anchor':
                $ref = $b['exam_ref'] ?? $b['exam'] ?? null;
                $eid = is_scalar($ref) ? ($examIds[(string) $ref] ?? null) : null;
                if ($eid === null) {
                    $this->warn($where . ': آزمون «' . (is_scalar($ref) ? $ref : '') . '» پیدا نشد.');
                    return false;
                }
                $data['checkpoint_exam_id'] = $eid;
                break;
        }

        $this->blocks->create($data);
        return true;
    }

    /** The text of a block, including the structured forms of the clinical types. */
    private function blockText(string $type, array $b): ?string
    {
        $raw = $b['text'] ?? $b['body'] ?? $b['content'] ?? $b['message'] ?? null;

        if (in_array($type, ['vitals', 'lab'], true) && is_array($b['rows'] ?? null)) {
            $lines = [];
            foreach ($b['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cells = $type === 'vitals'
                    ? [$row['name'] ?? $row['label'] ?? '', $row['value'] ?? '', $row['unit'] ?? '', $row['flag'] ?? '']
                    : [$row['test'] ?? $row['name'] ?? '', $row['result'] ?? $row['value'] ?? '', $row['unit'] ?? '',
                       $row['range'] ?? $row['normal'] ?? '', $row['flag'] ?? ''];
                $cells = array_map(static fn ($c) => str_replace(['|', "\n"], ['/', ' '], trim(is_scalar($c) ? (string) $c : '')), $cells);
                if ($cells[0] !== '') {
                    $lines[] = rtrim(implode(' | ', $cells), ' |');
                }
            }
            $raw = implode("\n", $lines);
        } elseif (in_array($type, ['ddx', 'reference', 'pearl'], true) && is_array($b['items'] ?? $raw)) {
            $items = is_array($b['items'] ?? null) ? $b['items'] : $raw;
            $raw = implode("\n", array_filter(array_map(static fn ($i) => is_scalar($i) ? trim((string) $i) : '', $items)));
        }

        return $this->text($raw, 20000);
    }

    private function createQuestion(array $q, int $lessonId, ?int $stageId, array $opts, string $where): ?int
    {
        $prompt = $this->text($q['prompt'] ?? $q['question'] ?? $q['stem'] ?? null, 8000);
        if ($prompt === null) {
            $this->warn($where . ': متن سؤال خالی است و وارد نشد.');
            return null;
        }

        $list = $q['options'] ?? $q['choices'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        $answer = $q['answer'] ?? null;
        $answerIndex = null;
        if (is_int($answer) || (is_string($answer) && ctype_digit($answer))) {
            $answerIndex = (int) $answer - 1;
        } elseif (is_string($answer) && preg_match('/^[A-Ha-h]$/', trim($answer)) === 1) {
            $answerIndex = ord(strtoupper(trim($answer))) - 65;
        }

        $options = [];
        foreach (array_slice(array_values($list), 0, 8) as $i => $o) {
            if (is_scalar($o)) {
                $o = ['text' => (string) $o];
            }
            if (!is_array($o)) {
                continue;
            }
            $body = $this->text($o['text'] ?? $o['body'] ?? $o['option'] ?? null, 2000);
            if ($body === null) {
                continue;
            }
            $correct = filter_var($o['correct'] ?? $o['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($answerIndex !== null) {
                $correct = $i === $answerIndex;
            } elseif (is_string($answer) && trim($answer) === $body) {
                $correct = true;
            }
            $options[] = ['label' => chr(65 + count($options)), 'body' => $body, 'is_correct' => $correct];
        }

        $correctCount = count(array_filter($options, static fn (array $o): bool => $o['is_correct']));
        if (count($options) < 2 || $correctCount !== 1) {
            $this->warn($where . ': سؤال «' . mb_substr($prompt, 0, 40) . '…» باید دست‌کم ۲ گزینه و دقیقاً یک گزینه صحیح داشته باشد؛ وارد نشد.');
            return null;
        }

        $difficulty = self::DIFFICULTY[strtolower(trim((string) ($q['difficulty'] ?? 'medium')))] ?? 'medium';

        $trackIds = [];
        foreach ((array) ($q['skill_tracks'] ?? $q['tags'] ?? []) as $tag) {
            $tid = is_scalar($tag) ? $this->trackId((string) $tag, $opts) : null;
            if ($tid !== null) {
                $trackIds[] = $tid;
            }
        }

        $id = $this->questions->createWithOptions([
            'uuid'               => Str::uuid4(),
            'lesson_id'          => $lessonId,
            'stage_id'           => $stageId,
            'prompt'             => $prompt,
            'explanation'        => $this->text($q['explanation'] ?? null, 8000),
            'hint'               => $this->text($q['hint'] ?? null, 2000),
            'difficulty'         => $difficulty,
            'xp_reward'          => $this->int($q['xp_reward'] ?? $q['xp'] ?? 10, 0, 500),
            'is_required'        => array_key_exists('required', $q) ? $this->bool($q['required']) : true,
            'is_final_case_step' => $this->bool($q['final_case_step'] ?? false),
            'status'             => in_array($q['status'] ?? '', ['draft', 'published'], true) ? $q['status'] : 'published',
        ], $options, $trackIds);

        $this->report['counts']['questions']++;
        return $id;
    }

    private function createExam(array $e, int $lessonId, array $stageIds, array $qIds, array $opts, string $where): ?int
    {
        $title = $this->text($e['title'] ?? null, 191);
        if ($title === null) {
            $this->warn($where . ': عنوان آزمون خالی است و وارد نشد.');
            return null;
        }

        $position = in_array($e['position'] ?? '', ['before_stage', 'after_stage', 'after_lesson'], true) ? $e['position'] : 'after_lesson';
        $anchor   = $this->stageRef($e['anchor_stage'] ?? $e['stage'] ?? null, $stageIds);
        if ($position !== 'after_lesson' && $anchor === null) {
            $this->warn($where . ': مرحله مرجع آزمون پیدا نشد؛ «بعد از درس» ثبت شد.');
            $position = 'after_lesson';
        }

        $mode = ($e['mode'] ?? 'fixed_list') === 'random_pool' ? 'random_pool' : 'fixed_list';
        $primary = is_scalar($e['primary_skill_track'] ?? null) ? $this->trackId((string) $e['primary_skill_track'], $opts) : null;
        if ($mode === 'random_pool' && $primary === null) {
            $this->warn($where . ': آزمون تصادفی بدون مهارت اصلی ممکن نیست؛ «فهرست ثابت» ثبت شد.');
            $mode = 'fixed_list';
        }
        $secondary = [];
        foreach ((array) ($e['secondary_skill_tracks'] ?? []) as $tag) {
            $tid = is_scalar($tag) ? $this->trackId((string) $tag, $opts) : null;
            if ($tid !== null) {
                $secondary[] = $tid;
            }
        }

        // Inline questions are allowed too; they join the bank without a stage.
        $questionIds = [];
        foreach ((array) ($e['questions'] ?? []) as $k => $ref) {
            if (is_array($ref)) {
                $qid = $this->createQuestion($ref, $lessonId, null, $opts, $where . '، سؤال ' . fa((string) ((int) $k + 1)));
            } else {
                $qid = is_scalar($ref) ? ($qIds[(string) $ref] ?? null) : null;
                if ($qid === null) {
                    $this->warn($where . ': سؤال «' . (is_scalar($ref) ? $ref : '') . '» پیدا نشد.');
                }
            }
            if ($qid !== null) {
                $questionIds[] = $qid;
            }
        }

        $num = $this->int($e['num_questions'] ?? ($questionIds !== [] ? count($questionIds) : 10), 1, 100);

        $id = $this->exams->create([
            'uuid'                   => Str::uuid4(),
            'lesson_id'              => $lessonId,
            'position_type'          => $position,
            'anchor_stage_id'        => $position === 'after_lesson' ? null : $anchor,
            'title'                  => $title,
            'description'            => $this->text($e['description'] ?? null, 5000),
            'primary_skill_track_id' => $primary,
            'secondary_skill_track_ids' => $secondary,
            'question_source_mode'   => $mode,
            'num_questions'          => $num,
            'pass_threshold_percent' => $this->int($e['pass_percent'] ?? 70, 1, 100),
            'is_gating'              => $this->bool($e['gating'] ?? false),
            'max_attempts'           => isset($e['max_attempts']) && $e['max_attempts'] !== null ? $this->int($e['max_attempts'], 1, 100) : null,
            'cooldown_hours_between_attempts' => $this->int($e['cooldown_hours'] ?? 24, 0, 720),
            'time_limit_minutes'     => isset($e['time_limit_minutes']) && $e['time_limit_minutes'] !== null ? $this->int($e['time_limit_minutes'], 1, 600) : null,
            'xp_reward'              => $this->int($e['xp_reward'] ?? 50, 0, 5000),
            'display_order'          => 1000,
            'status'                 => $this->status($e['status'] ?? null, $opts, (string) ($opts['default_status'] ?? 'draft')),
        ]);

        foreach ($questionIds as $i => $qid) {
            $this->exams->attachQuestion($id, $qid, $i + 1);
        }
        if ($mode === 'fixed_list' && $questionIds === []) {
            $this->warn($where . ': آزمون سؤالی ندارد؛ از صفحه آزمون سؤال اضافه کنید.');
        }

        $this->report['counts']['exams']++;
        return $id;
    }

    private function mediaId(string $type, array $b, array $opts, string $where): ?int
    {
        $ref = $b['media'] ?? $b['media_uuid'] ?? null;
        if (is_string($ref) && preg_match('/^[0-9a-f-]{36}$/i', $ref) === 1) {
            $row = $this->media->findByUuid(strtolower($ref));
            if ($row !== null && $row['kind'] === $type) {
                return (int) $row['id'];
            }
            $this->warn($where . ': رسانه با این شناسه در کتابخانه رسانه نیست.');
            return null;
        }

        $data = $b['image'] ?? $b['data_url'] ?? (is_string($ref) ? $ref : null);
        if ($type === 'image' && is_string($data) && preg_match('~^data:image/[a-z.+-]+;base64,~i', $data) === 1) {
            $bytes = base64_decode(substr($data, (int) strpos($data, ',') + 1), true);
            if ($bytes === false || $bytes === '') {
                $this->warn($where . ': تصویر base64 معتبر نیست.');
                return null;
            }
            if ($opts['dry_run']) {
                // Checked, not written: the row below is rolled back with
                // everything else, but a file would not be.
                if (@getimagesizefromstring($bytes) === false) {
                    $this->warn($where . ': فایل تصویر معتبر نیست.');
                    return null;
                }
                return $this->media->create([
                    'uuid' => Str::uuid4(), 'kind' => 'image', 'storage_path' => 'image/dry-run',
                    'mime' => 'image/png', 'byte_size' => strlen($bytes), 'alt_text' => 'dry-run',
                ]);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'bimg');
            if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
                $this->warn($where . ': ذخیره موقت تصویر ناموفق بود.');
                return null;
            }
            try {
                $stored = BalinMediaStorage::store(['tmp_name' => $tmp, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK], 'image');
            } catch (\Throwable $e) {
                @unlink($tmp);
                $this->warn($where . ': ' . $e->getMessage());
                return null;
            }
            $this->freshMedia[] = $stored['storage_path'];
            $alt = $this->text($b['alt'] ?? $b['caption'] ?? null, 255) ?? 'تصویر بالینی';

            return $this->media->create([
                'uuid'          => Str::uuid4(),
                'kind'          => 'image',
                'storage_path'  => $stored['storage_path'],
                'original_name' => 'import.' . pathinfo($stored['storage_path'], PATHINFO_EXTENSION),
                'mime'          => $stored['mime'],
                'byte_size'     => $stored['byte_size'],
                'alt_text'      => $alt,
                'caption'       => $this->text($b['caption'] ?? null, 255),
                'checksum'      => $stored['checksum'],
                'uploaded_by'   => $opts['author'],
            ]);
        }

        $this->warn($where . ': برای بلوک «' . $type . '» شناسه رسانه (از کتابخانه رسانه) لازم است' . ($type === 'image' ? ' یا تصویر به شکل data:image/…;base64' : '') . '؛ وارد نشد.');
        return null;
    }

    /* ------------------------------------------------------- catalogues */

    private function loadCatalog(): void
    {
        foreach ($this->characters->all() as $c) {
            $this->charByName[self::norm((string) $c['name'])] ??= (int) $c['id'];
        }
        foreach ($this->tracks->all() as $t) {
            $this->trackByKey['s:' . strtolower((string) $t['slug'])] = (int) $t['id'];
            $this->trackByKey['n:' . self::norm((string) $t['name'])] ??= (int) $t['id'];
            if ($t['name_en']) {
                $this->trackByKey['n:' . self::norm((string) $t['name_en'])] ??= (int) $t['id'];
            }
        }
    }

    private function importCharacters(array $list): void
    {
        foreach (array_slice($list, 0, 100) as $c) {
            if (!is_array($c) || ($name = $this->text($c['name'] ?? null, 120)) === null) {
                continue;
            }
            if (isset($this->charByName[self::norm($name)])) {
                continue;
            }
            $this->characterId($name, ['create_missing' => true] + ['dry_run' => false], $c);
        }
    }

    private function characterId(string $name, array $opts, array $hint = []): ?int
    {
        $key = self::norm($name);
        if (isset($this->charByName[$key])) {
            return $this->charByName[$key];
        }
        if (empty($opts['create_missing'])) {
            return null;
        }
        $type = in_array($hint['character_type'] ?? $hint['type_of'] ?? ($hint['type'] ?? ''), self::CHAR_TYPES, true)
            ? ($hint['character_type'] ?? $hint['type_of'] ?? $hint['type'])
            : $this->guessCharType($name);
        $id = $this->characters->create([
            'uuid'      => Str::uuid4(),
            'name'      => $name,
            'char_type' => $type,
            'gender'    => ($hint['gender'] ?? '') === 'female' ? 'female' : 'male',
            'icon'      => $this->text($hint['icon'] ?? null, 32) ?? ['teacher' => '👨‍🏫', 'doctor' => '👨‍⚕️', 'patient' => '🤒', 'nurse' => '👩‍⚕️', 'student' => '🧑‍🎓', 'other' => '🙂'][$type],
            'side'      => in_array($hint['side'] ?? '', ['left', 'right'], true) ? $hint['side'] : ($type === 'patient' ? 'left' : 'right'),
            'color'     => $this->color($hint['color'] ?? null),
            'is_active' => true,
        ]);
        $this->report['counts']['characters']++;

        return $this->charByName[$key] = $id;
    }

    private function guessCharType(string $name): string
    {
        return match (true) {
            (bool) preg_match('/بیمار|patient/iu', $name) => 'patient',
            (bool) preg_match('/پرستار|nurse/iu', $name)  => 'nurse',
            (bool) preg_match('/استاد|اتند|attending|professor/iu', $name) => 'teacher',
            (bool) preg_match('/دکتر|رزیدنت|dr\.?|resident/iu', $name) => 'doctor',
            (bool) preg_match('/کارورز|اینترن|استاژر|intern|student|دانشجو/iu', $name) => 'student',
            default => 'other',
        };
    }

    private function importTracks(array $list): void
    {
        foreach (array_slice($list, 0, 100) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = $this->text($t['name'] ?? null, 120);
            if ($name === null) {
                continue;
            }
            $this->trackId((string) ($t['slug'] ?? $name), ['create_missing' => true], $t);
        }
    }

    private function trackId(string $key, array $opts, array $hint = []): ?int
    {
        $slug = Str::slug($key);
        $found = $this->trackByKey['s:' . strtolower($key)] ?? $this->trackByKey['s:' . $slug] ?? $this->trackByKey['n:' . self::norm($key)] ?? null;
        if ($found !== null || empty($opts['create_missing'])) {
            return $found;
        }
        $name = $this->text($hint['name'] ?? null, 120) ?? $key;
        $categories = ['clinical_reasoning', 'procedural', 'communication', 'documentation', 'professionalism'];
        $id = $this->tracks->create([
            'uuid'        => Str::uuid4(),
            'name'        => mb_substr($name, 0, 120),
            'name_en'     => $this->text($hint['name_en'] ?? null, 120),
            'slug'        => mb_substr($slug, 0, 120),
            'icon'        => $this->text($hint['icon'] ?? null, 32),
            'color'       => $this->color($hint['color'] ?? null),
            'description' => $this->text($hint['description'] ?? null, 2000),
            'category'    => in_array($hint['category'] ?? '', $categories, true) ? $hint['category'] : 'clinical_reasoning',
            'is_active'   => true,
        ]);
        $this->report['counts']['tracks']++;
        $this->trackByKey['s:' . $slug] = $id;
        $this->trackByKey['n:' . self::norm($name)] = $id;

        return $id;
    }

    /* --------------------------------------------------------- helpers */

    private function stageRef(mixed $ref, array $stageIds): ?int
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            return $stageIds['#' . (int) $ref] ?? null;
        }
        if (!is_string($ref) || trim($ref) === '') {
            return null;
        }
        return $stageIds[$ref] ?? $stageIds['t:' . self::norm($ref)] ?? null;
    }

    private function uniqueSlug(string $source): string
    {
        $base = mb_substr(Str::slug($source), 0, 100);
        $slug = $base;
        for ($n = 2; $this->lessons->slugExists($slug) && $n < 500; $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    private function status(mixed $value, array $opts, string $default): string
    {
        $status = match ($opts['status']) {
            'draft'     => 'draft',
            'published' => 'published',
            default     => in_array($value, ['draft', 'published'], true) ? $value : $default,
        };
        return $status === 'published' && !$opts['can_publish'] ? 'draft' : $status;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim(str_replace("\r\n", "\n", (string) $value));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function int(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $min));
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? $value : null;
    }

    private function warn(string $message): void
    {
        if (count($this->report['warnings']) < 300) {
            $this->report['warnings'][] = $message;
        }
    }

    private function error(string $message): void
    {
        $this->report['errors'][] = $message;
    }

    private static function norm(string $s): string
    {
        $s = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $s);
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? $s);
    }
}
