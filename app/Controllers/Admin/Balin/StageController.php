<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinBlockRepository;
use HeleXa\Models\Balin\BalinCharacterRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinMediaRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * The stage builder: the ordered blocks that make up a clinical scene.
 *
 * Blocks are added, edited, reordered and removed one at a time rather than
 * saved as a whole document. That keeps each action small enough to be
 * unambiguous, and means two admins working on different blocks of the same
 * stage do not collide at all.
 */
final class StageController extends Controller
{
    private const STALE = 'این بلوک توسط مدیر دیگری تغییر کرده است. صفحه را دوباره بارگذاری کن.';

    /** Block types that carry their own text rather than pointing elsewhere. */
    private const TEXT_BLOCKS = ['chat', 'text', 'finding', 'hint', 'warning', 'system',
                                 'vitals', 'lab', 'pearl', 'ddx', 'reference'];

    public function __construct(
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinBlockRepository $blocks = new BalinBlockRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
    ) {
    }

    public function show(Request $request, array $params = []): Response
    {
        $stage  = $this->find((string) ($params['uuid'] ?? ''));
        $lesson = $this->lessons->findById((int) $stage['lesson_id']);

        return $this->page('layouts.app', 'admin.balin.stages.builder', [
            'title'      => $stage['title'],
            'stage'      => $stage,
            'lesson'     => $lesson,
            'blocks'     => $this->blocks->forStage((int) $stage['id']),
            'characters' => (new BalinCharacterRepository())->all(true),
            'questions'  => (new BalinQuestionRepository())->forLesson((int) $stage['lesson_id']),
            'media'      => (new BalinMediaRepository())->all(),
            'exams'      => (new BalinCheckpointRepository())->forLesson((int) $stage['lesson_id']),
            'clinical'   => (new \HeleXa\Services\Balin\BalinTransfer())->clinicalReady(),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $stage = $this->find((string) ($params['uuid'] ?? ''));
        $title = trim($request->string('title'));

        if ($title === '') {
            $this->flash('error', 'عنوان مرحله الزامی است.');
            return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
        }

        $saved = $this->stages->update((int) $stage['id'], [
            'title'             => $title,
            'subtitle'          => trim($request->string('subtitle')) ?: null,
            'description'       => trim($request->string('description')) ?: null,
            'is_final_case'     => $request->bool('is_final_case'),
            'xp_reward'         => max(0, $request->int('xp_reward')),
            'estimated_minutes' => max(0, $request->int('estimated_minutes')),
        ], $request->int('version'));

        $this->flash($saved ? 'success' : 'error', $saved ? 'مرحله ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $stage  = $this->find((string) ($params['uuid'] ?? ''));
        $status = $request->string('status');

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw HttpException::notFound();
        }

        $this->stages->setStatus((int) $stage['id'], $status);

        ActivityLogger::log('balin.stage.status', Auth::id(), 'balin_stage', (int) $stage['id'],
            ['status' => $status], 'notice', $request);
        $this->flash('success', 'وضعیت مرحله تغییر کرد.');

        return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $stage  = $this->find((string) ($params['uuid'] ?? ''));
        $lesson = $this->lessons->findById((int) $stage['lesson_id']);

        $this->stages->delete((int) $stage['id']);

        ActivityLogger::log('balin.stage.deleted', Auth::id(), 'balin_stage', (int) $stage['id'],
            ['title' => $stage['title']], 'warning', $request);
        $this->flash('success', 'مرحله حذف شد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid']);
    }

    // -------------------------------------------------------------- blocks

    public function storeBlock(Request $request, array $params = []): Response
    {
        $stage = $this->find((string) ($params['uuid'] ?? ''));
        $type  = $request->string('block_type');

        $error = $this->validateBlock($request, $type);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
        }

        $id = $this->blocks->create($this->collectBlock($request, $type) + [
            'uuid'     => Str::uuid4(),
            'stage_id' => (int) $stage['id'],
        ]);

        ActivityLogger::log('balin.block.created', Auth::id(), 'balin_block', $id,
            ['stage' => (int) $stage['id'], 'type' => $type], 'info', $request);
        $this->flash('success', 'بلوک اضافه شد.');

        return $this->redirect('/admin/balin/stages/' . $stage['uuid'] . '#block-' . $id);
    }

    public function updateBlock(Request $request, array $params = []): Response
    {
        $block = $this->blocks->findByUuid((string) ($params['block'] ?? ''));
        if ($block === null) {
            throw HttpException::notFound();
        }

        $stage = $this->stages->findById((int) $block['stage_id']);
        $type  = $request->string('block_type', (string) $block['block_type']);

        $error = $this->validateBlock($request, $type);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
        }

        $saved = $this->blocks->update(
            (int) $block['id'],
            $this->collectBlock($request, $type),
            $request->int('version')
        );

        $this->flash($saved ? 'success' : 'error', $saved ? 'بلوک ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/stages/' . $stage['uuid'] . '#block-' . $block['id']);
    }

    public function moveBlock(Request $request, array $params = []): Response
    {
        $block = $this->blocks->findByUuid((string) ($params['block'] ?? ''));
        if ($block === null) {
            throw HttpException::notFound();
        }

        $stage     = $this->stages->findById((int) $block['stage_id']);
        $direction = $request->string('direction');
        $siblings  = $this->blocks->forStage((int) $block['stage_id']);

        $index = null;
        foreach ($siblings as $position => $row) {
            if ((int) $row['id'] === (int) $block['id']) {
                $index = $position;
                break;
            }
        }

        $target = $direction === 'up' ? ($index ?? 0) - 1 : ($index ?? 0) + 1;

        // Swapping the two order values moves one block past its neighbour
        // without touching any of the others.
        if ($index !== null && isset($siblings[$target])) {
            $this->blocks->setOrder((int) $block['id'], (int) $siblings[$target]['display_order']);
            $this->blocks->setOrder((int) $siblings[$target]['id'], (int) $block['display_order']);
        }

        return $this->redirect('/admin/balin/stages/' . $stage['uuid'] . '#block-' . $block['id']);
    }

    public function duplicateBlock(Request $request, array $params = []): Response
    {
        $block = $this->blocks->findByUuid((string) ($params['block'] ?? ''));
        if ($block === null) {
            throw HttpException::notFound();
        }

        $stage = $this->stages->findById((int) $block['stage_id']);

        $copy = $block;
        unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['version']);
        $copy['uuid']          = Str::uuid4();
        $copy['display_order'] = (int) $block['display_order'] + 1;
        $copy['settings']      = $block['settings'] === null
            ? null
            : json_decode((string) $block['settings'], true);

        $id = $this->blocks->create($copy);
        $this->flash('success', 'بلوک تکثیر شد.');

        return $this->redirect('/admin/balin/stages/' . $stage['uuid'] . '#block-' . $id);
    }

    public function destroyBlock(Request $request, array $params = []): Response
    {
        $block = $this->blocks->findByUuid((string) ($params['block'] ?? ''));
        if ($block === null) {
            throw HttpException::notFound();
        }

        $stage = $this->stages->findById((int) $block['stage_id']);
        $this->blocks->delete((int) $block['id']);

        $this->flash('success', 'بلوک حذف شد.');

        return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
    }

    /** Reopens the gaps between order values after many insertions. */
    public function rebalance(Request $request, array $params = []): Response
    {
        $stage = $this->find((string) ($params['uuid'] ?? ''));

        $this->blocks->rebalance((int) $stage['id']);
        $this->flash('success', 'ترتیب بلوک‌ها مرتب شد.');

        return $this->redirect('/admin/balin/stages/' . $stage['uuid']);
    }

    // ------------------------------------------------------------- helpers

    private function find(string $uuid): array
    {
        $stage = $this->stages->findByUuid($uuid);

        if ($stage === null) {
            throw HttpException::notFound();
        }

        return $stage;
    }

    /**
     * Each block type needs a different thing to be useful. Catching that
     * here means a half-built block never reaches a student's screen as an
     * empty bubble.
     */
    private function validateBlock(Request $request, string $type): ?string
    {
        $allowed = ['chat', 'question', 'image', 'audio', 'video', 'text', 'finding',
                    'hint', 'warning', 'system', 'divider', 'checkpoint_anchor'];
        // The clinical types need their migration; without it MySQL would
        // reject the value, so they are offered only once it has run.
        if ((new \HeleXa\Services\Balin\BalinTransfer())->clinicalReady()) {
            array_push($allowed, 'vitals', 'lab', 'pearl', 'ddx', 'reference');
        }

        if (!in_array($type, $allowed, true)) {
            return 'نوع بلوک معتبر نیست.';
        }

        if (in_array($type, self::TEXT_BLOCKS, true) && trim($request->string('body')) === '') {
            return 'متن این بلوک نمی‌تواند خالی باشد.';
        }
        if ($type === 'chat' && $request->int('character_id') <= 0) {
            return 'برای پیام گفت‌وگو باید یک شخصیت انتخاب کنی.';
        }
        if ($type === 'question' && $request->int('question_id') <= 0) {
            return 'برای بلوک سؤال باید یک سؤال انتخاب کنی.';
        }
        if (in_array($type, ['image', 'audio', 'video'], true) && $request->int('media_id') <= 0) {
            return 'برای این بلوک باید یک فایل رسانه انتخاب کنی.';
        }
        if ($type === 'checkpoint_anchor' && $request->int('checkpoint_exam_id') <= 0) {
            return 'برای این بلوک باید یک آزمون انتخاب کنی.';
        }

        return null;
    }

    private function collectBlock(Request $request, string $type): array
    {
        $side = $request->string('side_override');

        return [
            'block_type'         => $type,
            'is_required'        => $request->bool('is_required'),
            'status'             => in_array($request->string('status'), ['draft', 'published', 'archived'], true)
                                        ? $request->string('status')
                                        : 'published',
            'character_id'       => $type === 'chat' ? ($request->int('character_id') ?: null) : null,
            'side_override'      => in_array($side, ['left', 'right'], true) ? $side : null,
            'body'               => trim($request->string('body')) ?: null,
            'media_id'           => in_array($type, ['image', 'audio', 'video'], true)
                                        ? ($request->int('media_id') ?: null)
                                        : null,
            'question_id'        => $type === 'question' ? ($request->int('question_id') ?: null) : null,
            'checkpoint_exam_id' => $type === 'checkpoint_anchor'
                                        ? ($request->int('checkpoint_exam_id') ?: null)
                                        : null,
            'animation'          => trim($request->string('animation')) ?: null,
        ];
    }
}
